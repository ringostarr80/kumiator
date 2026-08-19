<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use App\Services\WebAuthn\Contracts\PasskeyRegistrationContract;
use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use App\Services\WebAuthn\PasskeyLoginContext;
use App\Services\WebAuthn\PasskeyRegistrationService;
use App\Services\WebAuthn\WebAuthnCeremonySession;
use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Registriert alle WebAuthn-Bindungen im Service-Container.
 *
 * Der WebAuthn-Serializer ist ein Singleton, weil sein Aufbau über die
 * Normalizer-Kette von Symfony vergleichsweise teuer und er selbst zustandslos
 * ist. Die übrigen Services entstehen pro Request neu.
 */
final class WebAuthnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SerializerInterface::class, static function (): SerializerInterface {
            $attestationManager = new AttestationStatementSupportManager([
                new NoneAttestationStatementSupport(),
            ]);

            return (new WebauthnSerializerFactory($attestationManager))->create();
        });

        $this->app->singleton(WebAuthnValidatorFactoryContract::class, WebAuthnValidatorFactory::class);

        $this->app->bind(
            WebAuthnCeremonySessionContract::class,
            fn (): WebAuthnCeremonySession => new WebAuthnCeremonySession(
                $this->app->make(SerializerInterface::class),
            ),
        );

        $this->app->bind(PasskeyCredentialRepositoryContract::class, PasskeyCredentialRepository::class);

        // `scoped`, damit Setzer (`PasskeyAuthenticationService`) und Leser
        // (`LogAuthenticationActivityListener`) dieselbe Request-Instanz sehen.
        $this->app->scoped(PasskeyLoginContext::class);

        $this->app->bind(
            PasskeyRegistrationContract::class,
            fn (): PasskeyRegistrationService => new PasskeyRegistrationService(
                $this->app->make(WebAuthnValidatorFactoryContract::class),
                $this->app->make(PasskeyCredentialRepositoryContract::class),
                $this->app->make(SerializerInterface::class),
            ),
        );

        $this->app->bind(
            PasskeyAuthenticationContract::class,
            fn (): PasskeyAuthenticationService => new PasskeyAuthenticationService(
                $this->app->make(WebAuthnValidatorFactoryContract::class),
                $this->app->make(PasskeyCredentialRepositoryContract::class),
                $this->app->make(SerializerInterface::class),
                $this->app->make(PasskeyLoginContext::class),
            ),
        );
    }

    public function boot(): void
    {
        // beim Booten ist nichts zu tun
    }
}
