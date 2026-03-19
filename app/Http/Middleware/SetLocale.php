<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = ['de', 'en'];
        $locale = session('locale', config('app.locale'));

        if (in_array($locale, $supported)) {
            assert(is_string($locale));
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
