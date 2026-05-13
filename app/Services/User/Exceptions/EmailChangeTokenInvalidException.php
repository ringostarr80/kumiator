<?php

declare(strict_types=1);

namespace App\Services\User\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn ein Confirm-/Cancel-Token zu keinem `pending_email_token_hash`
 * eines Users passt. Forensisch relevant: Unterscheidet einen unbekannten
 * Versuch (potenziell Token-Guessing) vom abgelaufenen, aber gültigen Token.
 */
final class EmailChangeTokenInvalidException extends RuntimeException
{
}
