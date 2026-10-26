<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HTTP Basic Auth in front of the whole site. It is on only when
 * both BASIC_AUTH_USER and BASIC_AUTH_PASSWORD are set.
 */
class BasicAuthGate
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('auth.basic_gate.user');
        $password = config('auth.basic_gate.password');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            return $next($request);
        }

        $userMatches = hash_equals($user, (string) $request->getUser());
        $passwordMatches = hash_equals($password, (string) $request->getPassword());

        if ($userMatches && $passwordMatches) {
            return $next($request);
        }

        return response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="Impruve Booking", charset="UTF-8"',
        ]);
    }
}
