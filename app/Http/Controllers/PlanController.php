<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Customer;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;

class PlanController extends Controller
{
    /**
     * Display a listing of all plans.
     */
    public function index()
    {
        $plans = Plan::orderBy('price', 'asc')->get();
        return response()->json($plans);
    }

    // app/Http/Controllers/PlanController.php

    public function subscribe(Request $request)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $plan = Plan::findOrFail($request->plan_id);
        $user = Auth::user();

        if ($plan->price > 0 && empty($plan->stripe_price_id)) {
            return response()->json(['error' => 'ID do preço do plano não encontrado.'], 400);
        }

        // Adicionamos a lógica para criar o cliente do Stripe antes de qualquer outra coisa
        if (empty($user->stripe_customer_id)) {
            try {
                $customer = Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => ['app_user_id' => $user->id],
                ]);
                $user->stripe_customer_id = $customer->id;
                $user->save();
                Log::info('Novo cliente do Stripe criado e ID salvo.', ['customer_id' => $customer->id]);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Erro ao criar cliente no Stripe: ' . $e->getMessage());
                return response()->json(['error' => 'Erro ao criar cliente no Stripe.'], 500);
            }
        }

        $checkoutSessionData = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => config('app.url') . '/dashboard?success=true',
            'cancel_url' => config('app.url') . '/plans?canceled=true',
            'locale' => 'pt-BR',
            'customer' => $user->stripe_customer_id, // Usamos o ID que acabamos de criar ou que já existia
            'metadata' => [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ],
        ];

        Log::info('Tentando criar checkout session com:', $checkoutSessionData);

        try {
            $checkout_session = \Stripe\Checkout\Session::create($checkoutSessionData);
            return response()->json(['id' => $checkout_session->id]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Erro ao criar checkout session: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function handleWebhook(Request $request)
    {
        Log::info('Webhook do Stripe recebido.');
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $endpointSecret = env('STRIPE_WEBHOOK_SECRET');
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $event = null;

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Webhook - Payload inválido: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Webhook - Assinatura inválida: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            // Este evento é disparado quando a assinatura é criada com sucesso
            case 'checkout.session.completed':
                $session = $event->data->object;
                $userId = (int) ($session->metadata->user_id ?? 0);

                if ($session->subscription && $userId) {
                    $user = User::find($userId);
                    if ($user) {
                        // opcional: recuperar a assinatura para garantir priceId
                        $subscription = \Stripe\Subscription::retrieve($session->subscription);
                        $priceId = $subscription->items->data[0]->price->id ?? null;
                        $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;

                        $user->stripe_subscription_id = $session->subscription;
                        if ($plan) {
                            $user->plan_id = $plan->id;
                        }
                        $user->save();
                    }
                }
                break;
            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                $valid = in_array($subscription->status, ['active', 'trialing', 'incomplete', 'past_due', 'unpaid', 'paused'], true);
                if ($valid) {
                    $newPriceId = $subscription->items->data[0]->price->id ?? null;
                    if ($newPriceId) {
                        $plan = Plan::where('stripe_price_id', $newPriceId)->first();
                        if ($plan) {
                            $user = User::where('stripe_subscription_id', $subscription->id)->first();
                            if ($user) {
                                $user->plan_id = $plan->id;
                                $user->save();
                            }
                        }
                    }
                }
                break;
            // Este evento é disparado quando a assinatura é cancelada
            case 'customer.subscription.deleted':
                Log::info('Webhook - Evento de cancelamento de assinatura recebido.');
                $subscription = $event->data->object;

                $user = User::where('stripe_subscription_id', $subscription->id)->first();

                if ($user) {
                    $user->plan_id = 1; // Define o plano como o gratuito
                    $user->stripe_subscription_id = null; // Limpa o ID da assinatura
                    $user->save();
                    Log::info("Webhook - Assinatura de {$user->id} cancelada. Plano alterado para 1 (Gratuito).");
                }
                break;
            default:
                Log::info('Webhook - Outro tipo de evento recebido: ' . $event->type);
                break;
        }

        return response()->json(['message' => 'Webhook processado com sucesso'], 200);
    }

    public function cancelPlan()
    {
        $user = Auth::user();
        if ($user && $user->plan_id !== 1 && $user->stripe_subscription_id) {
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

            $subscription = \Stripe\Subscription::retrieve($user->stripe_subscription_id);
            $subscription->cancel();

            // O webhook irá lidar com a atualização final do banco de dados
            return response()->json(['message' => 'Solicitação de cancelamento enviada. A confirmação será recebida em breve.'], 200);
        }

        return response()->json(['message' => 'Nenhum plano pago para cancelar.'], 400);
    }
    public function getSubscriptionDetails()
    {
        $user = Auth::user();

        if (empty($user->stripe_customer_id)) {
            return response()->json([
                'subscription' => null,
                'current_plan' => null,
                'current_period_end' => null,
                'invoices' => [],
            ]);
        }

        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            // 1) Pega assinaturas sem filtrar por status e escolhe a mais recente "válida"
            $subs = \Stripe\Subscription::all([
                'customer' => $user->stripe_customer_id,
                'limit'    => 10,
                // 'expand' => ['data.items.data.price'], // opcional
            ]);

            $validStatuses = ['active', 'trialing', 'incomplete', 'past_due', 'unpaid', 'paused'];
            $subscription = collect($subs->data)
                ->sortByDesc('created')
                ->first(fn($s) => in_array($s->status, $validStatuses, true));

            // 2) Se não houver assinatura válida, retorna vazio
            if (!$subscription) {
                return response()->json([
                    'subscription' => null,
                    'current_plan' => null,
                    'current_period_end' => null,
                    'invoices' => [],
                ]);
            }

            // 3) Deriva plano e período
            $priceId = $subscription->items->data[0]->price->id ?? null;
            $periodEnd = $subscription->current_period_end ?? null;

            // 4) Fallback opcional: tenta pegar do upcoming invoice
            if (!$periodEnd) {
                try {
                    $upcoming = \Stripe\Invoice::upcoming([
                        'customer'     => $user->stripe_customer_id,
                        'subscription' => $subscription->id,
                    ]);
                    // tenta usar a data da linha ou do próprio invoice
                    $periodEnd = $upcoming->lines->data[0]->period->end
                        ?? $upcoming->period_end
                        ?? null;
                } catch (\Exception $e) {
                    // se não houver upcoming (p.ex. no fim do ciclo), segue sem fallback
                }
            }

            // 5) Faturas (histórico)
            $invoices = \Stripe\Invoice::all([
                'customer' => $user->stripe_customer_id,
                'limit'    => 10
            ]);

            return response()->json([
                'subscription'       => $subscription,         // objeto completo (tem current_period_end)
                'current_plan'       => $priceId,              // ajuda no front
                'current_period_end' => $periodEnd,            // em segundos
                'invoices'           => $invoices->data,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Erro ao buscar detalhes da assinatura: ' . $e->getMessage());
            return response()->json([
                'subscription' => null,
                'current_plan' => null,
                'current_period_end' => null,
                'invoices' => [],
            ]);
        }
    }
}
