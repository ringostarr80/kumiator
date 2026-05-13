<?php

declare(strict_types=1);

namespace App\Services\User\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn beim Confirm die angefragte `pending_email` zwischenzeitlich
 * von einem anderen User belegt wurde (Race: zwei pending-Anfragen auf
 * dieselbe Adresse, der erste gewinnt). Der Service räumt die `pending_email*`-
 * Felder des unterlegenen Users beim Wurf mit, damit derselbe Token nicht
 * erneut zum gleichen Fehler führt.
 */
final class EmailChangeConflictException extends RuntimeException
{
}
