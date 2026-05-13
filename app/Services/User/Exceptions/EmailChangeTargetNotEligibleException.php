<?php

declare(strict_types=1);

namespace App\Services\User\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn der zum Token gehörende User für einen Confirm nicht mehr
 * berechtigt ist — aktuell der Fall, sobald der Account soft-deleted ist.
 * Wir reaktivieren keinen gelöschten Account über einen Email-Confirm-Link.
 */
final class EmailChangeTargetNotEligibleException extends RuntimeException
{
}
