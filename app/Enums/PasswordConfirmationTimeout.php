<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Frist in Sekunden für Aktionen, deren Folgen sich nicht zurücknehmen lassen
 * oder die Sitzung überdauern. Livewire-Komponenten und Routen prüfen sie an
 * verschiedenen Enden desselben Wegs; eine abweichende Zahl an einem Ende
 * ließe den Weg dort weiter offen.
 */
enum PasswordConfirmationTimeout: int
{
    case FRESH = 300;
}
