<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Der Session-Schlüssel der Passwortbestätigung gehört Laravel, nicht diesem
 * Projekt; er steht deshalb nur an dieser einen Stelle. Ohne die Bestätigung
 * weisen die betroffenen Routen mit 423 ab und die Livewire-Komponenten mit
 * 403, statt den geprüften Pfad überhaupt zu erreichen.
 *
 * @phpstan-require-extends \Tests\TestCase
 */
trait ConfirmsPassword
{
    protected function confirmPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }
}
