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

        $endpointSecret = config('services.stripe.webhook_secret');
        $sigHeader = $request->header('Stripe-Signature');
        $payload = $request->getContent();

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\Throwable $e) {
            Log::error('Webhook inválido: ' . $e->getMessage());
            return response()->json(['error' => 'invalid'], 400);
        }

        // StripeClient com a SECRET correta (atenção: live vs test)
        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

        // Se for Stripe Connect, o ID da connected account vem aqui:
        $requestOptions = [];
        if (!empty($event->account)) {
            $requestOptions['stripe_account'] = $event->account; // guarde isso no usuário também
        }

        switch ($event->type) {
            case 'checkout.session.completed': {
                    $session = $event->data->object;

                    $userId = (int)($session->metadata->user_id ?? 0);
                    $freePlanId = 9; // ajuste se necessário
                    if (!$userId) break;

                    $user = \App\Models\User::find($userId);
                    if (!$user) break;

                    $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

                    // Se usar Connect:
                    $requestOptions = [];
                    if (!empty($event->account)) {
                        $requestOptions['stripe_account'] = $event->account;
                        $user->stripe_account_id = $event->account;
                    }

                    // Sempre salve o customer
                    $user->stripe_customer_id = is_string($session->customer) ? $session->customer : ($session->customer->id ?? null);

                    $isFreeFlow = ((int)($session->metadata->plan_id ?? 0) === $freePlanId) || ((int)$session->amount_total === 0);

                    if ($isFreeFlow) {
                        // downgrade para o plano grátis: não mantenha assinatura no banco
                        if (!empty($session->subscription)) {
                            try {
                                $stripe->subscriptions->cancel($session->subscription, [], $requestOptions);
                            } catch (\Throwable $e) {
                                Log::warning('Falha ao cancelar sub zero-valor', ['sub' => $session->subscription, 'err' => $e->getMessage()]);
                            }
                        }

                        $user->plan_id = $freePlanId;
                        $user->stripe_subscription_id = null;
                        $user->cancel_at_period_end = null;
                        $user->current_period_end = null;
                        $user->save();
                        break;
                    }

                    // Fluxo pago normal
                    $user->stripe_subscription_id = is_string($session->subscription) ? $session->subscription : null;

                    if ($user->stripe_subscription_id) {
                        $sub = $stripe->subscriptions->retrieve($user->stripe_subscription_id, [], $requestOptions);
                        $priceId = $sub->items->data[0]->price->id ?? null;
                        if ($priceId && ($plan = \App\Models\Plan::where('stripe_price_id', $priceId)->first())) {
                            $user->plan_id = $plan->id;
                        }
                        $ts = isset($sub->current_period_end) ? (int)$sub->current_period_end : 0;
                        $user->cancel_at_period_end = (bool)($sub->cancel_at_period_end ?? false);
                        $user->current_period_end = $ts ? \Carbon\Carbon::createFromTimestamp($ts) : null;
                    }

                    $user->save();
                    break;
                }

            case 'invoice.paid': {
                    /** @var \Stripe\Invoice $invoice */
                    $invoice = $event->data->object;

                    $customerId = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? null);
                    if (!$customerId) break;

                    $user = \App\Models\User::where('stripe_customer_id', $customerId)->first();
                    if (!$user) {
                        Log::warning('invoice.paid sem usuário', ['customer' => $customerId]);
                        break;
                    }

                    // NÃO mexa em stripe_subscription_id aqui.
                    // Descubra o price da linha de assinatura:
                    $priceId = null;
                    foreach ($invoice->lines->data as $line) {
                        if (($line->type ?? null) === 'subscription') {
                            if (!empty($line->price)) {
                                $priceId = is_string($line->price) ? $line->price : ($line->price->id ?? null);
                            }
                            break;
                        }
                    }

                    // Se não encontrou no invoice, busca na assinatura
                    if (!$priceId && !empty($invoice->subscription)) {
                        $sub = $stripe->subscriptions->retrieve($invoice->subscription, [], $requestOptions);
                        $priceId = $sub->items->data[0]->price->id ?? null;
                    }

                    if ($priceId && ($plan = \App\Models\Plan::where('stripe_price_id', $priceId)->first())) {
                        $user->plan_id = $plan->id;
                    }

                    $user->save();
                    Log::info('invoice.paid atualizado', [
                        'user_id' => $user->id,
                        'plan_id' => $user->plan_id,
                        'sub_id'  => $user->stripe_subscription_id,
                    ]);
                    break;
                }

            case 'customer.subscription.created': {
                    $sub = $event->data->object;
                    $user = \App\Models\User::where('stripe_customer_id', $sub->customer)->first();
                    if ($user) {
                        $user->stripe_subscription_id = $sub->id; // confirma o ID
                        $priceId = $sub->items->data[0]->price->id ?? null;
                        if ($priceId && ($plan = \App\Models\Plan::where('stripe_price_id', $priceId)->first())) {
                            $user->plan_id = $plan->id;
                        }
                        $ts = isset($sub->current_period_end) ? (int)$sub->current_period_end : null;
                        $user->current_period_end = $ts ? \Carbon\Carbon::createFromTimestamp($ts) : null;
                        $user->cancel_at_period_end = (bool)($sub->cancel_at_period_end ?? false);
                        if (!empty($event->account)) $user->stripe_account_id = $event->account;
                        $user->save();
                    }
                    break;
                }

            case 'customer.subscription.updated': {
                    $sub = $event->data->object;

                    $user = \App\Models\User::where('stripe_subscription_id', $sub->id)->first()
                        ?: \App\Models\User::where('stripe_customer_id', $sub->customer)->first();

                    if ($user) {
                        // atualiza plano pelo price
                        $priceId = $sub->items->data[0]->price->id ?? null;
                        if ($priceId && ($plan = \App\Models\Plan::where('stripe_price_id', $priceId)->first())) {
                            $user->plan_id = $plan->id;
                        }
                        $ts = isset($sub->current_period_end) ? (int)$sub->current_period_end : null;
                        $user->current_period_end = $ts ? \Carbon\Carbon::createFromTimestamp($ts) : null;
                        $user->cancel_at_period_end = (bool)($sub->cancel_at_period_end ?? false);
                        $user->save();
                    }
                    break;
                }

            case 'customer.subscription.deleted': {
                    $sub = $event->data->object;
                    $user = \App\Models\User::where('stripe_subscription_id', $sub->id)->first();
                    if ($user) {
                        $user->plan_id = 1;
                        $user->stripe_subscription_id = null;
                        $user->cancel_at_period_end = null;
                        $user->current_period_end = null;
                        $user->save();
                    }
                    break;
                }

            default:
                Log::info('Webhook ignorado: ' . $event->type);
        }

        return response()->json(['ok' => true]);
    }

    public function cancelPlan(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->stripe_subscription_id) {
            return response()->json(['message' => 'Nenhuma assinatura ativa para cancelar.'], 400);
        }

        $atPeriodEnd = $request->boolean('at_period_end', true);

        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

        // Se usar Stripe Connect, inclua a conta conectada nas chamadas
        $requestOptions = [];
        if (!empty($user->stripe_account_id)) {
            $requestOptions['stripe_account'] = $user->stripe_account_id;
        }

        // helper pra não repetir
        $markLocalAsCanceled = function () use ($user) {
            $user->plan_id = 9; // seu plano gratuito
            $user->stripe_subscription_id = null;
            $user->cancel_at_period_end = null;
            $user->current_period_end = null;
            $user->save();
        };

        // helper p/ setar datas com guard e evitar Carbon null
        $setPeriodFields = function ($sub) use ($user) {
            $ts = isset($sub->current_period_end) ? (int)$sub->current_period_end : null;
            $user->cancel_at_period_end = (bool)($sub->cancel_at_period_end ?? false);
            $user->current_period_end   = $ts ? \Carbon\Carbon::createFromTimestamp($ts) : null;
            $user->save();
        };

        try {
            // 1) Recupera a assinatura primeiro (e já trata idempotência)
            $subscription = $stripe->subscriptions->retrieve($user->stripe_subscription_id, [], $requestOptions);

            if (($subscription->status ?? null) === 'canceled') {
                // Já estava cancelada → alinhar local e retornar sucesso
                $markLocalAsCanceled();
                return response()->json(['message' => 'Assinatura já estava cancelada.'], 200);
            }

            // 2) Cancelar (no fim do período ou agora)
            if ($atPeriodEnd) {
                $subscription = $stripe->subscriptions->update(
                    $user->stripe_subscription_id,
                    ['cancel_at_period_end' => true],
                    $requestOptions
                );
                $setPeriodFields($subscription);

                return response()->json([
                    'message' => 'Cancelamento agendado para o fim do período.',
                    'status'  => $subscription->status,
                    'cancel_at_period_end' => (bool)$subscription->cancel_at_period_end,
                    'current_period_end'   => $subscription->current_period_end,
                ], 200);
            } else {
                $subscription = $stripe->subscriptions->cancel($user->stripe_subscription_id, [], $requestOptions);
                // cancelou de vez: já limpa local
                if (($subscription->status ?? null) === 'canceled') {
                    $markLocalAsCanceled();
                }
                return response()->json([
                    'message' => 'Assinatura cancelada imediatamente.',
                    'status'  => $subscription->status,
                ], 200);
            }
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $msg = $e->getMessage() ?? '';

            // Caso 1: assinatura não existe neste ambiente/conta (mismatch live/test ou Connect)
            if (stripos($msg, 'No such subscription') !== false) {
                $markLocalAsCanceled();
                return response()->json([
                    'message' => 'Assinatura não encontrada no Stripe. Status local marcado como cancelado.',
                    'hint'    => 'Verifique se a SECRET do backend e a conta (ou `stripe_account`) são as mesmas onde a assinatura foi criada.',
                ], 200);
            }

            // Caso 2: já cancelada e tentou atualizar campos não permitidos
            if (stripos($msg, 'canceled subscription can only update its cancellation_details') !== false) {
                $markLocalAsCanceled();
                return response()->json([
                    'message' => 'Assinatura já estava cancelada no Stripe. Status local sincronizado.',
                ], 200);
            }

            report($e);
            return response()->json(['message' => 'Erro ao cancelar no Stripe: ' . $msg], 422);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            report($e);
            return response()->json(['message' => 'Erro ao cancelar no Stripe: ' . $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erro interno ao cancelar.'], 500);
        }
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
