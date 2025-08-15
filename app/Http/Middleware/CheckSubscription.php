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

        if (!$user) {
            return $next($request);
        }

        if (empty($user->stripe_subscription_id) || ($user->current_period_end && $user->current_period_end->isPast())) {
            // AQUI: Retornamos um JSON com um status 403
            return response()->json(['message' => 'O seu plano expirou. Por favor, escolha um plano para continuar.'], 403);
        }

        return $next($request);
    }
}
