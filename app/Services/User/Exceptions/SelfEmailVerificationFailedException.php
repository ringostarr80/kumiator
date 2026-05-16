<?php

declare(strict_types=1);

namespace App\Services\User\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn die self-service E-Mail-Verifizierung über den signierten
 * Link fehlschlägt — entweder weil die User-ID zu keinem Konto auflöst
 * (`user_not_found`) oder weil der Hash nicht zur aktuellen E-Mail passt
 * (`hash_mismatch`). Der Controller mappt das auf `abort(403)`; im Service
 * wurde vorher bereits ein anonymisierter Audit-Eintrag geschrieben.
 *
 * Es gibt keine separaten Exception-Klassen pro Reason — der semantische
 * Unterschied ist forensisch interessant (steckt im Audit-Eintrag), aber
 * für das HTTP-Verhalten identisch (beides → 403, kein UI-Unterschied,
 * damit keine Existenz-Oracle entstehen).
 */
final class SelfEmailVerificationFailedException extends RuntimeException
{
}
