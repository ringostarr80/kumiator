<?php

declare(strict_types=1);

namespace App\Services\User\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn der Confirm-Token zwar einem User zugeordnet werden konnte,
 * `pending_email_sent_at` aber älter als die konfigurierte TTL ist
 * (60 Min — siehe Plan-Festlegung). Der Service räumt die abgelaufenen
 * `pending_email*`-Felder beim Wurf gleich mit, damit keine Datenleiche bleibt.
 */
final class EmailChangeTokenExpiredException extends RuntimeException
{
}
