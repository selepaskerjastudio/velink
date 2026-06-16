<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::exists()) {
            abort(403, 'Registration is closed.');
        }

        return $next($request);
    }
}
