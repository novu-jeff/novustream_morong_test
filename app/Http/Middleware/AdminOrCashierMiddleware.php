<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminOrCashierMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('admins')->user();

        if (!$user || !in_array($user->role, ['admin', 'cashier'])) {
            return redirect()->route('auth.login')->with('error', 'Access denied.');
        }

        return $next($request);
    }
}
