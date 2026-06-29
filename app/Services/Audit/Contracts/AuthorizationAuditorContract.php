<?php

declare(strict_types=1);

namespace App\Services\Audit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Schreibt `authorization_denied`-Audit-Einträge für abgelehnte Zugriffe ohne
 * geprüftes Subjekt (z. B. die reine Anzeige-Permission `activity-log.view`).
 *
 * Existiert als Contract, weil die Livewire-Präsentationsschicht laut
 * Architektur-Regel nur Service-Contracts kennen darf, nicht den konkreten
 * Auditor. Der subjekt-behaftete `Gate::after`-Einstieg ist ein interner Hook
 * und bleibt darum der konkreten Klasse vorbehalten.
 */
interface AuthorizationAuditorContract
{
    public function recordSubjectlessDenial(?Authenticatable $causer, string $ability): void;
}
