<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\PasskeyCredentialRepository;
use App\Repositories\PasskeyCredentialRepositoryContract;
use App\Services\WebAuthn\PasskeyAuthenticationContract;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use App\Services\WebAuthn\PasskeyRegistrationContract;
use App\Services\WebAuthn\PasskeyRegistrationService;
use App\Services\WebAuthn\WebAuthnServerService;
use Illuminate\Support\ServiceProvider;

/**
 * Registers all WebAuthn-related bindings in the service container.
 *
 * The WebAuthnServerService is a singleton because building the Symfony
 * Serializer is relatively expensive and the serializer is stateless.
 * The other services are resolved freshly each request (transient).
 */
final class WebAuthnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebAuthnServerService::class);

        $this->app->bind(
            PasskeyCredentialRepositoryContract::class,
            fn (): PasskeyCredentialRepositoryContract => new PasskeyCredentialRepository(
                $this->app->make(WebAuthnServerService::class)->getSerializer(),
            ),
        );

        $this->app->bind(
            PasskeyRegistrationContract::class,
            fn (): PasskeyRegistrationService => new PasskeyRegistrationService(
                $this->app->make(WebAuthnServerService::class),
                $this->app->make(PasskeyCredentialRepositoryContract::class),
            ),
        );

        $this->app->bind(
            PasskeyAuthenticationContract::class,
            fn (): PasskeyAuthenticationService => new PasskeyAuthenticationService(
                $this->app->make(WebAuthnServerService::class),
                $this->app->make(PasskeyCredentialRepositoryContract::class),
            ),
        );
    }

    public function boot(): void
    {
        // no boot-time work needed
    }
}
