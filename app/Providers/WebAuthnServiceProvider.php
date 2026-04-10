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
use App\Services\WebAuthn\PasskeyRegistrationService;
use App\Services\WebAuthn\WebAuthnCeremonySession;
use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Registers all WebAuthn-related bindings in the service container.
 *
 * The WebAuthn serializer is a singleton because building it via the
 * Symfony normalizer chain is relatively expensive and it is stateless.
 * The other services are resolved freshly each request (transient).
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

        $this->app->bind(
            PasskeyCredentialRepositoryContract::class,
            fn (): PasskeyCredentialRepository => new PasskeyCredentialRepository(
                $this->app->make(SerializerInterface::class),
            ),
        );

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
            ),
        );
    }

    public function boot(): void
    {
        // no boot-time work needed
    }
}
