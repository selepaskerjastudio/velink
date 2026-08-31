<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates the session of a user who has been deactivated.
 *
 * Sessions live in Redis (SESSION_DRIVER=redis), so deactivation cannot be
 * enforced by deleting rows from a sessions table — an already-logged-in user
 * would keep their access until the session expired on its own. Checking on
 * every request is what makes deactivation take effect immediately.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
