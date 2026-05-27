<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\Auth\SelfRegistrationContext;
use App\Services\Auth\UnapprovedLoginContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Spatie\Activitylog\Facades\Activity;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UnapprovedLoginContextContract::class, UnapprovedLoginContext::class);
        $this->app->bind(SelfRegistrationContextContract::class, SelfRegistrationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Re-Auth-Failures vor 2FA-Endpoints sichtbar machen. Beide vendor-
        // seitigen Pfade — der Web-Form-Pfad über `ConfirmablePasswordController`
        // und der Livewire-Pfad über Jetstreams `ConfirmsPasswords::confirmPassword()`
        // — laufen durch `Laravel\Fortify\Actions\ConfirmPassword`. Wird hier ein
        // Callback registriert, delegiert die Action an ihn (siehe
        // `vendor/laravel/fortify/src/Actions/ConfirmPassword.php`), und wir
        // fangen beide Pfade an einer Stelle ab. Hash-Vergleich parität zur
        // `authenticateUsing`-Override oben.
        Fortify::confirmPasswordsUsing(static function (User $user, ?string $password): bool {
            if (is_string($password) && Hash::check($password, $user->password)) {
                return true;
            }

            Activity::useLog('auth')
                ->event('password_confirmation_failed')
                ->causedBy($user)
                ->log(__('app.activity_password_confirmation_failed'));

            return false;
        });

        $unapprovedContext = $this->app->make(UnapprovedLoginContextContract::class);

        Fortify::authenticateUsing(static function (Request $request) use ($unapprovedContext): ?User {
            $email = $request->input('email');
            assert(is_string($email));

            $password = $request->input('password');
            assert(is_string($password));

            $user = User::where('email', $email)->first();

            if ($user === null || !Hash::check($password, $user->password)) {
                return null;
            }

            // Identität verifiziert, aber Konto noch nicht freigeschaltet:
            // separater Audit-Eintrag, damit unapproved-Versuche scharf von
            // generischen Login-Fehlern abgegrenzt werden können. Der Marker
            // unterdrückt zugleich den nachgelagerten `login_failed`-Eintrag,
            // den Fortify nach dem `null`-Return über `Auth\Events\Failed`
            // auslöst (siehe `LogAuthenticationActivityListener::handleFailed`).
            if ($user->approved_at === null) {
                $unapprovedContext->record($user, 'web', $email);

                return null;
            }

            return $user;
        });

        RateLimiter::for('login', static function (Request $request) {
            $username = $request->input(Fortify::username()) ?? '';
            assert(is_string($username));
            $throttleKey = Str::transliterate(Str::lower($username) . '|' . $request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for(
            'two-factor',
            static fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')),
        );
    }
}
