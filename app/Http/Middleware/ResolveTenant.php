<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resolve the current tenant from the authenticated user and bind to container.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $tenant = $user->tenant;

            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }

        return $next($request);
    }
}
