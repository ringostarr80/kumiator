<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

/**
 * Contract für den `OtherDeviceLogoutContext`-Marker.
 *
 * Die Trennung in ein Interface ist nötig, weil Livewire-Komponenten laut
 * Architektur-Regel (`LivewireComponentsAreIndependentTest`) keine konkreten
 * Services kennen dürfen — DI erfolgt ausschließlich über Contracts. Das
 * `LogoutOtherBrowserSessionsForm` injiziert dieses Contract per `boot()`,
 * der `LogAuthenticationActivityListener` (Listeners sind von der Regel
 * ausgenommen) ruft die konkrete Implementation statisch.
 */
interface OtherDeviceLogoutContextContract
{
    public function markActive(): void;

    public function clear(): void;
}
