<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TwoFactorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if ($user && $user->two_factor_authentification && !session('2fa_passed')) {
            return response()->json([
                'requires_2fa' => true,
                'message' => 'Code 2FA requis'
            ], 401);
        }

        return $next($request);
    }
}