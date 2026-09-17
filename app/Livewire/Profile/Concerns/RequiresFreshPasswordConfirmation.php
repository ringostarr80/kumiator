<?php

declare(strict_types=1);

namespace App\Livewire\Profile\Concerns;

use Laravel\Jetstream\ConfirmsPasswords;

/**
 * Verkürzt die Bestätigungsfrist für Aktionen, deren Folgen sich nicht
 * zurücknehmen lassen. Die drei Stunden aus `auth.password_timeout` tragen die
 * Profilbereiche, in denen ein Fehlgriff korrigierbar bleibt — eine Löschung
 * ist es nicht, und ein Zwangs-Logout sperrt fremde Geräte aus.
 *
 * Beide Enden des Wegs brauchen dieselbe Frist: Prüfte sie nur die Aktion,
 * winkte `startConfirmingPassword()` den Klick mit der alten Bestätigung durch
 * und der Nutzer liefe in ein 403, ohne dass ihm ein Dialog die Bestätigung
 * angeboten hätte.
 */
trait RequiresFreshPasswordConfirmation
{
    use ConfirmsPasswords;

    private const int FRESH_CONFIRMATION_SECONDS = 300;

    /**
     * Deckt sich mit Jetstreams Original bis auf die Frist — der Trait nimmt
     * dafür keinen Parameter entgegen.
     */
    public function startConfirmingPassword(string $confirmableId): void
    {
        $this->resetErrorBag();

        if ($this->passwordIsConfirmed($this->confirmationWindowFor($confirmableId))) {
            $this->dispatch('password-confirmed', id: $confirmableId);

            return;
        }

        $this->confirmingPassword = true;
        $this->confirmableId = $confirmableId;
        $this->confirmablePassword = '';

        $this->dispatch('confirming-password');
    }

    /**
     * Welche Aktionen die kurze Frist tragen — `null` heißt alle, damit eine
     * Komponente mit einer einzigen bestätigten Aktion nichts benennen muss.
     * Genannt wird der `wire:then`-Wert der Blade-Komponente `confirms-password`.
     *
     * @return ?list<string>
     */
    protected function freshlyConfirmedActions(): ?array
    {
        return null;
    }

    /**
     * Die Blade-Komponente reicht die Aktion nur als Hash ihres `wire:then`-Werts
     * durch, weil der Wert Anführungszeichen tragen kann; der Vergleich bildet
     * ihn deshalb genauso. `null` lässt Jetstream auf `auth.password_timeout`
     * zurückfallen.
     */
    private function confirmationWindowFor(string $confirmableId): ?int
    {
        $actions = $this->freshlyConfirmedActions();

        if ($actions === null || in_array($confirmableId, array_map(md5(...), $actions), true)) {
            return self::FRESH_CONFIRMATION_SECONDS;
        }

        return null;
    }
}
