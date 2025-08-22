<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Customer;
use Stripe\Stripe;
use Stripe\Subscription;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // A Lógica de Validação permanece a mesma
        $request->validate([
            'name' => 'required|string|max:255',
            'fantasia' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'document' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            // Define a chave secreta do Stripe
            Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

            // Define o ID do plano que queres usar para o teste gratuito
            // Por exemplo, se o teu plano "Completo" tiver ID 1 na tabela `plans`, usa 1.
            $planToTrialId = 4;
            $trialDays = 14;

            // 1. Encontra o plano na tua base de dados
            $paidPlan = Plan::findOrFail($planToTrialId);

            // 2. Obtém o stripe_price_id do plano
            $stripePriceId = $paidPlan->stripe_price_id;

            if (empty($stripePriceId)) {
                throw new \Exception("ID do preço do Stripe não encontrado para o plano com ID {$planToTrialId}.");
            }

            // 3. Cria o cliente no Stripe
            $customer = Customer::create([
                'email' => $request->email,
                'name' => $request->name,
            ]);

            // 4. Cria a subscrição no Stripe com período de teste
            $subscription = Subscription::create([
                'customer' => $customer->id,
                'items' => [
                    ['price' => $stripePriceId],
                ],
                'trial_end' => now()->addDays($trialDays)->timestamp,
            ]);

            // 5. Cria o utilizador na tua base de dados com as informações da subscrição
            $user = User::create([
                'name' => $request->name,
                'fantasia' => $request->fantasia,
                'phone' => $request->phone,
                'document' => $request->document,
                'address' => $request->address,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'plan_id' => $paidPlan->id,
                'stripe_customer_id' => $customer->id,
                'stripe_subscription_id' => $subscription->id,
                'current_period_end' => now()->addDays($trialDays),
            ]);

            Log::info('Novo utilizador registado e subscrição de teste criada.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
            ]);

            return response()->json([
                'message' => 'Utilizador registado com sucesso, o teu teste de 14 dias começou!',
            ], 201);
        } catch (\Throwable $e) {
            // Se algo correr mal, registamos o erro
            Log::error('Erro ao registar utilizador ou criar subscrição Stripe: ' . $e->getMessage());

            // Podes devolver uma resposta de erro genérica ou mais específica
            return response()->json([
                'message' => 'Ocorreu um erro no registo. Por favor, tenta novamente.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        return $this->respondWithToken($token);
    }

    public function logout()
    {
        Auth::logout();
        return response()->json(['message' => 'Sessão encerrada com sucesso!']);
    }

    public function user()
    {
        $user = Auth::user();
        if ($user) {
            $user->load('plan');
            return response()->json($user);
        }
        return response()->json(['error' => 'Não autenticado'], 401);
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60,
            'user' => Auth::user()->load('plan'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Validação dos dados
        $request->validate([
            'name' => 'required|string|max:255',
            'fantasia' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'document' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        // Atualiza os dados do usuário
        $user->update($request->all());

        return response()->json(['message' => 'Perfil atualizado com sucesso!']);
    }

    public function profile(Request $request)
    {
        // Retorna os dados do usuário autenticado
        return response()->json($request->user());
    }

    public function hasPix(int $userId)
    {
        $profissional = User::find($userId);

        if (!$profissional) {
            return response()->json(['message' => 'Profissional não encontrado.'], 404);
        }

        // Pega a configuração Pix do profissional.
        // Usamos o operador de coalescência nula (??) para evitar erros caso não exista a configuração.
        $pixConfig = $profissional->pixConfig ?? null;

        // Define se o profissional tem uma chave Pix.
        $hasPixKey = (bool) $pixConfig;

        // Define se o profissional aceita apenas Pix.
        // Se a configuração existir, usamos o valor de 'accepts_only_pix'.
        // Caso contrário, o valor padrão é false.
        $acceptsOnlyPix = $pixConfig ? $pixConfig->accepts_only_pix : false;

        // Retorna um objeto com as duas propriedades
        return response()->json([
            'hasPixKey' => $hasPixKey,
            'acceptsOnlyPix' => $acceptsOnlyPix,
        ]);
    }

    public function getRegrasAgendamento(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'regras' => $user->regras_agendamento ?? ''
        ]);
    }

    /**
     * Atualiza a regra de agendamento para o usuário autenticado.
     */
    public function updateRegrasAgendamento(Request $request)
    {
        $request->validate([
            'regras' => 'nullable|string',
        ]);

        $user = $request->user();
        $user->regras_agendamento = $request->input('regras');
        $user->save();

        return response()->json(['message' => 'Regra de agendamento atualizada com sucesso.']);
    }
}
