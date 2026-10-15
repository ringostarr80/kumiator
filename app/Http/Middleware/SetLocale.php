<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = ['de', 'en'];
        $locale = session('locale', config('app.locale'));

        if (in_array($locale, $supported, true)) {
            app()->setLocale($locale);
        }

        // Gequeuete Mails rendern im Worker, der keine Session kennt und sonst
        // auf `APP_LOCALE` zurückfiele. Laravel wendet das nur auf den nächsten
        // Versand an; wer mehrere Notifications pro Request verschickt, setzt
        // die Sprache an jeder selbst.
        Notification::locale(app()->getLocale());

        return $next($request);
    }
}
