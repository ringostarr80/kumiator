<?php

declare(strict_types=1);

namespace App\Services\Upload\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn ein Profilfoto nicht auf die Disk
 * geschrieben werden kann — `storePublicly()` liefert auf der
 * `throw => false`-Disk bei einem Schreibfehler (Platte voll, S3 down)
 * `false`. Ein dedizierter Typ trennt diesen Infra-Fehler von den
 * fachlichen Optimizer-Fehlern und propagiert bewusst als reportete 500,
 * statt (wie ProfilePhotoOptimizationException) als Feld-Validierung.
 */
final class ProfilePhotoStorageException extends RuntimeException
{
}
