<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

/**
 * Die Selbstregistrierung verrät nicht, ob zu einer Adresse schon ein Konto
 * besteht: Jede gültige Eingabe endet gleich, von einer vergebenen Adresse
 * erfährt nur ihr Inhaber, per Mail.
 */
interface SelfRegistrarContract
{
    /**
     * @param array<mixed> $input
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register(array $input): void;
}
