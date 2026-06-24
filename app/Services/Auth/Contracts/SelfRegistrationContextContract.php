<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

/**
 * Contract für den `SelfRegistrationContext`-Marker.
 *
 * Die Trennung in ein Interface ist nötig, weil Actions laut Architektur-
 * Regel (`ActionsAreIndependentTest`) keine konkreten Services kennen
 * dürfen — DI erfolgt ausschließlich über Contracts. `CreateNewUser`
 * setzt den Marker um den `User::create()`-Aufruf herum, damit der
 * `Activity::saving`-Listener im `AppServiceProvider` den generischen
 * `created`-Eintrag im `user`-Log auf den fachlichen Code
 * `user_self_registered` umlabelt — symmetrisch zum Passkey-Pfad.
 */
interface SelfRegistrationContextContract
{
    public function markActive(): void;

    public function isActive(): bool;

    public function clear(): void;
}
