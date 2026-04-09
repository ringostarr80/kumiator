<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests whose body exceeds the configured byte limit.
 *
 * Usage in routes:
 *   ->middleware('max.json.body:65536')
 *
 * The limit is given in bytes. When omitted, the default of 65 536 bytes
 * (64 KiB) is used — well above any legitimate WebAuthn response while still
 * protecting against oversized payloads being fed to the deserializer.
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
