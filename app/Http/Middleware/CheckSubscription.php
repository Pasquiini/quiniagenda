<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        // Se o utilizador não está autenticado, permite o acesso (rotas públicas)
        if (!$user) {
            return $next($request);
        }

        // Se o utilizador não tiver uma subscrição do Stripe
        // ou a subscrição tiver expirado
        if (empty($user->stripe_subscription_id) || ($user->current_period_end && $user->current_period_end->isPast())) {
            // Redireciona o utilizador para a página de planos
            return redirect('/plans')->with('error', 'O seu plano expirou. Por favor, escolha um plano para continuar.');
        }

        return $next($request);
    }
}
