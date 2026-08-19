<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Erzeugt die Zeremonie-Validatoren für Registrierung und Anmeldung.
 *
 * Reine Factory ohne Zustand: Sie richtet die Zeremonie-Maschinerie der
 * webauthn-lib lediglich auf die erlaubten Origins ein.
 */
final class WebAuthnValidatorFactory implements WebAuthnValidatorFactoryContract
{
    /**
     * Jedes Mal eine neue Instanz, weil sie leichtgewichtig ist.
     */
    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator
    {
        $factory = $this->buildConfiguredStepManagerFactory($appUrl);

        return AuthenticatorAttestationResponseValidator::create(
            $factory->creationCeremony(),
        );
    }

    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator
    {
        $factory = $this->buildConfiguredStepManagerFactory($appUrl);

        return AuthenticatorAssertionResponseValidator::create(
            $factory->requestCeremony(),
        );
    }

    /**
     * Öffentlich, damit ein mitschneidender Validator dieselbe Zeremonie
     * bekommt: nachgebaut würde sie unbemerkt von dieser Konfiguration
     * abweichen.
     */
    public function buildConfiguredStepManagerFactory(string $appUrl): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        // Der Factory mitteilen, welche Origins gültig sind, damit CheckOrigin /
        // CheckAllowedOrigins in beiden Zeremonien durchlaufen.
        $factory->setAllowedOrigins([$appUrl]);

        return $factory;
    }
}
