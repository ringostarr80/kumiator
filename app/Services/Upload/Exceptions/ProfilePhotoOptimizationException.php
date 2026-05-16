<?php

declare(strict_types=1);

namespace App\Services\Upload\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn der ProfilePhotoOptimizer ein hochgeladenes Bild nicht in
 * ein AVIF-Thumbnail überführen kann — sei es beim Lesen der Eingabe, bei
 * der GD-Verarbeitung (Rotation/Resize/Encode) oder beim Anlegen der
 * Zieldatei. Ein dedizierter Typ erlaubt zielgerichtetes Catchen, ohne
 * unbeteiligte `RuntimeException`s im Stack mitzunehmen.
 */
final class ProfilePhotoOptimizationException extends RuntimeException
{
}
