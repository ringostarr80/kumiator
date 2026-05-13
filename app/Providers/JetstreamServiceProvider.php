<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Jetstream\DeleteUser;
use App\Livewire\Profile\ApiTokenManager;
use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Livewire\Profile\PasskeyManagerForm;
use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use App\Services\Auth\OtherDeviceLogoutContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtherDeviceLogoutContextContract::class, OtherDeviceLogoutContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::deleteUsersUsing(DeleteUser::class);

        Livewire::component('profile.passkey-manager-form', PasskeyManagerForm::class);
        Livewire::component('profile.logout-other-browser-sessions-form', LogoutOtherBrowserSessionsForm::class);
        Livewire::component('api.api-token-manager', ApiTokenManager::class);
    }

    /**
     * Configure the permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        Jetstream::permissions([
            'create',
            'read',
            'update',
            'delete',
        ]);
    }
}
