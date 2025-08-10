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
                Log::info('Webhook - Evento de checkout.session.completed recebido.');
                $session = $event->data->object;

                $userId = (int) $session->metadata->user_id;
                $planId = (int) $session->metadata->plan_id;

                // Salva o ID da assinatura no banco de dados do usuário
                if ($session->subscription) {
                    $user = User::find($userId);
                    if ($user) {
                        $user->plan_id = $planId;
                        $user->stripe_subscription_id = $session->subscription;
                        $user->save();
                        Log::info("Webhook - Assinatura do usuário {$user->id} salva. Plano atualizado para {$planId}.");
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

        // Garante que o usuário tem um ID de cliente do Stripe
        if (empty($user->stripe_customer_id)) {
            return response()->json(['error' => 'Nenhum cliente do Stripe associado.'], 404);
        }

        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

        try {
            // 1. Busca a assinatura ativa do cliente
            $subscriptions = Subscription::all([
                'customer' => $user->stripe_customer_id,
                'status' => 'active',
                'limit' => 1
            ]);

            $subscription = $subscriptions->data[0] ?? null;

            if (!$subscription) {
                return response()->json(['error' => 'Nenhuma assinatura ativa encontrada.'], 404);
            }

            // 2. Busca as últimas faturas do cliente
            $invoices = Invoice::all([
                'customer' => $user->stripe_customer_id,
                'limit' => 10
            ]);

            // 3. Retorna os dados para o front-end
            return response()->json([
                'current_plan' => $subscription->plan->id, // ID do plano no Stripe
                'current_period_end' => $subscription->current_period_end,
                'invoices' => $invoices->data,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Erro ao buscar detalhes da assinatura: ' . $e->getMessage());
            return response()->json(['error' => 'Não foi possível buscar os detalhes da assinatura.'], 500);
        }
    }
}
