<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bestandsänderungen, die dem Kontoinhaber per Mail gemeldet werden. Das
 * Umbenennen fehlt bewusst: Es verändert keinen Zugang, und ein getarnter
 * fremder Passkey ist mit seinem Hinzufügen bereits gemeldet.
 */
enum PasskeyChange: string
{
    case ADDED = 'added';
    case REMOVED = 'removed';
}
