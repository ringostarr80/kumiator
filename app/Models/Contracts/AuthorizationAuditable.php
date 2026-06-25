<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * Opt-in-Marker: Wird ein so markiertes Model bei einem Gate-Check abgelehnt,
 * schreibt der zentrale `Gate::after`-Hook einen `authorization_denied`-Eintrag.
 *
 * Bewusst opt-in statt „jede abgelehnte Ability": dieselbe Ability dient
 * andernorts der reinen UI-Sichtbarkeit (`@can` im Blade), deren Ablehnung kein
 * Security-Event ist und das Forensik-Log sonst pro Seitenaufruf fluten würde.
 * Das Marker-Tag macht explizit, dass eine Ablehnung auf diesem Subjekt ein
 * auditierungswürdiger Zugriffsversuch ist.
 */
interface AuthorizationAuditable
{
}
