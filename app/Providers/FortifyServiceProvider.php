<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
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
        // `scoped` statt `bind`: Marker-Setzer (Fortify-Closure) und -Leser
        // (`Failed`-Listener) müssen dieselbe Request-Instanz sehen.
        $this->app->scoped(UnapprovedLoginContextContract::class, UnapprovedLoginContext::class);
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

            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::PASSWORD_CONFIRMATION_FAILED->value)
                ->causedBy($user)
                ->log(ActivityEvent::PASSWORD_CONFIRMATION_FAILED->description());

            return false;
        });

        Fortify::authenticateUsing(static function (Request $request): ?User {
            /** @var string $email */
            $email = $request->input('email');

            /** @var string $password */
            $password = $request->input('password');

            $user = User::where('email', $email)->first();

            if ($user === null) {
                // Timing-Angleichung gegen E-Mail-Enumeration: Ohne KDF-Lauf
                // antwortet der Unbekannt-Pfad messbar schneller als „bekannte
                // E-Mail, falsches Passwort". `Hash::make` kostet einen Lauf
                // wie `Hash::check` und folgt Treiber und Cost der
                // Konfiguration — dasselbe Muster wie der Fake-Lookup im
                // Passkey-Options-Endpoint.
                Hash::make($password);

                return null;
            }

            if (!Hash::check($password, $user->password)) {
                return null;
            }

            // Identität verifiziert, aber Konto noch nicht freigeschaltet:
            // separater Audit-Eintrag, damit unapproved-Versuche scharf von
            // generischen Login-Fehlern abgegrenzt werden können. Der Marker
            // unterdrückt zugleich den nachgelagerten `login_failed`-Eintrag,
            // den Fortify nach dem `null`-Return über `Auth\Events\Failed`
            // auslöst (siehe `LogAuthenticationActivityListener::handleFailed`).
            if ($user->approved_at === null) {
                // Lazy auflösen: Die Closure wird einmal pro Prozess registriert
                // und überlebt den Request — eine beim Boot gecapturte Instanz
                // wäre unter Long-Running-Workern nicht die scoped Instanz des
                // laufenden Requests.
                app(UnapprovedLoginContextContract::class)->record($user, 'web', $email);

                return null;
            }

            return $user;
        });

        RateLimiter::for('login', static function (Request $request) {
            /** @var string $username */
            $username = $request->input(Fortify::username()) ?? '';
            $throttleKey = Str::transliterate(Str::lower($username) . '|' . $request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for(
            'two-factor',
            static fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')),
        );
    }
}
