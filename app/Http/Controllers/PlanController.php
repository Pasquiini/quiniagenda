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
            case 'invoice.paid':
                $invoice = $event->data->object;

                $subscriptionId = $invoice->subscription; // ex: sub_...
                $customerId     = $invoice->customer;     // ex: cus_...
                $priceId        = $invoice->lines->data[0]->price->id ?? null;

                // Ideal: tenha stripe_customer_id salvo no usuário
                $user = User::where('stripe_customer_id', $customerId)->first();

                // fallback
                if (!$user && $subscriptionId) {
                    $user = User::where('stripe_subscription_id', $subscriptionId)->first();
                }

                if ($user) {
                    $user->stripe_subscription_id = $subscriptionId;

                    if ($priceId) {
                        if ($plan = Plan::where('stripe_price_id', $priceId)->first()) {
                            $user->plan_id = $plan->id;
                        }
                    }

                    $user->save();
                }
                break;
            case 'customer.subscription.created':
                $subscription = $event->data->object;
                $priceId = $subscription->items->data[0]->price->id ?? null;

                $user = User::where('stripe_customer_id', $subscription->customer)->first();
                if ($user) {
                    $user->stripe_subscription_id = $subscription->id;
                    if ($priceId && ($plan = Plan::where('stripe_price_id', $priceId)->first())) {
                        $user->plan_id = $plan->id;
                    }
                    $user->save();
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
        try {
            $user = Auth::user();
            if (!$user) {
                // sem autenticação => 401 explícito
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            if (empty($user->stripe_customer_id)) {
                return response()->json([
                    'subscription' => null,
                    'current_plan' => null,
                    'current_period_end' => null,
                    'invoices' => [],
                ], 200);
            }

            // Use config/services.php (evita erro de env/cache)
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            // Busque assinaturas sem filtrar só por "active"
            $subs = \Stripe\Subscription::all([
                'customer' => $user->stripe_customer_id,
                'limit'    => 10,
                // 'expand' => ['data.items.data.price'], // opcional
            ]);

            $validStatuses = ['active', 'trialing', 'incomplete', 'past_due', 'unpaid', 'paused'];
            $subscription = collect($subs->data ?? [])
                ->sortByDesc('created')
                ->first(fn($s) => in_array($s->status, $validStatuses, true));

            if (!$subscription) {
                // Sem assinatura “utilizável”
                return response()->json([
                    'subscription'       => null,
                    'current_plan'       => null,
                    'current_period_end' => null,
                    'invoices'           => [],
                ], 200);
            }

            $priceId   = $subscription->items->data[0]->price->id ?? null;
            $periodEnd = $subscription->current_period_end ?? null;

            // Histórico de faturas
            $invoices = \Stripe\Invoice::all([
                'customer' => $user->stripe_customer_id,
                'limit'    => 10
            ]);


            return response()->json([
                'subscription'       => $subscription,
                'current_plan'       => $priceId,
                'current_period_end' => $periodEnd,
                'invoices'           => $invoices->data ?? [],
            ], 200);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe API error (getSubscriptionDetails): ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            // Retorne 200 com payload vazio para não quebrar o front, ou 502 se preferir sinalizar erro
            return response()->json([
                'subscription' => null,
                'current_plan' => null,
                'current_period_end' => null,
                'invoices' => [],
                'error' => 'stripe_api_error'
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Unhandled error (getSubscriptionDetails): ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            // 500 com mensagem amigável
            return response()->json([
                'message' => 'Internal error fetching subscription details'
            ], 500);
        }
    }
}
