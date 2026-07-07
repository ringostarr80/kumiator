<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Jetstream\DeleteUser;
use App\Livewire\Profile\ApiTokenManager;
use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Livewire\Profile\PasskeyManagerForm;
use App\Livewire\Profile\UpdatePasswordForm;
use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use App\Services\Auth\OtherDeviceLogoutContext;
use Illuminate\Support\Facades\Config;
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
        // `scoped`, damit Setzer (`LogoutOtherBrowserSessionsForm`) und Leser
        // (`LogAuthenticationActivityListener`) dieselbe Request-Instanz sehen.
        $this->app->scoped(OtherDeviceLogoutContextContract::class, OtherDeviceLogoutContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();
        $this->configureFileUploadLimit();

        Jetstream::deleteUsersUsing(DeleteUser::class);

        Livewire::component('profile.passkey-manager-form', PasskeyManagerForm::class);
        Livewire::component('profile.logout-other-browser-sessions-form', LogoutOtherBrowserSessionsForm::class);
        Livewire::component('profile.update-password-form', UpdatePasswordForm::class);
        Livewire::component('profile.update-profile-information-form', UpdateProfileInformationForm::class);
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

    /**
     * Gleicht Livewires Temp-Upload-Limit an die app-seitige Profilfoto-
     * Obergrenze an. Ohne dieses Angleichen würde Livewire den Default von
     * 12 MB durchlassen und erst die Action beim Absenden ablehnen — mit dem
     * Angleichen wird eine zu große Datei schon bei der Auswahl abgewiesen,
     * und zwar mit dem korrekten Limit.
     *
     * `temporary_file_upload.rules` ist global für alle Livewire-Uploads;
     * aktuell ist der Profilfoto-Upload der einzige im Projekt. Kommt ein
     * Upload mit abweichendem Limit hinzu, gehört diese Regel pro Komponente
     * gesetzt statt global.
     */
    private function configureFileUploadLimit(): void
    {
        $maxKilobytes = Config::integer('jetstream.profile_photo_max_kilobytes');

        Config::set('livewire.temporary_file_upload.rules', [
            'required',
            'file',
            'max:' . $maxKilobytes,
        ]);
    }
}
