<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weist Requests ab, deren Body das konfigurierte Byte-Limit überschreitet.
 *
 * Einsatz in Routen:
 *   ->middleware('max.json.body:65536')
 *
 * Das Limit zählt Bytes. Ohne Angabe gelten 65 536 Bytes (64 KiB) — weit über
 * jeder legitimen WebAuthn-Antwort und dennoch eng genug, damit keine
 * überdimensionierten Payloads in den Deserializer laufen.
 */
final class MaxJsonBodySize
{
    private const int DEFAULT_MAX_BYTES = 65_536;

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, int $maxBytes = self::DEFAULT_MAX_BYTES): Response
    {
        if (!$request->isJson()) {
            return response()->json(
                ['message' => __('app.unsupported_media_type')],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        if (strlen($request->getContent()) > $maxBytes) {
            return response()->json(
                ['message' => __('app.request_payload_too_large')],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        return $next($request);
    }
}
