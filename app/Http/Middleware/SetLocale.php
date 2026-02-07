<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * Priority:
     * 1. Route-forced locale (/en/ prefix forces 'en')
     * 2. Cookie (user's saved preference)
     * 3. Default: 'sv' (Swedish product)
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $forceLocale = null): Response
    {
        $locale = $forceLocale
            ?? $request->cookie('locale')
            ?? 'sv';

        if (! in_array($locale, ['en', 'sv'])) {
            $locale = 'sv';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
