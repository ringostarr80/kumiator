<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\ActivityFailureReason;
use App\Models\User;
use App\Services\Auth\Contracts\DisabledPasswordLoginContextContract;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Auth\Contracts\SessionConfirmationAuditContract;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\Auth\DisabledPasswordLoginContext;
use App\Services\Auth\SelfRegistrationContext;
use App\Services\Auth\SessionConfirmationAudit;
use App\Services\Auth\UnapprovedLoginContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `scoped` statt `bind`: Marker-Setzer und -Leser müssen dieselbe
        // Request-Instanz sehen (UnapprovedLogin: Fortify-Closure ↔ `Failed`-
        // Listener; SelfRegistration: `CreateNewUser` ↔ `Activity::saving`-Hook).
        $this->app->scoped(UnapprovedLoginContextContract::class, UnapprovedLoginContext::class);
        $this->app->scoped(SelfRegistrationContextContract::class, SelfRegistrationContext::class);
        $this->app->scoped(DisabledPasswordLoginContextContract::class, DisabledPasswordLoginContext::class);
        $this->app->bind(SessionConfirmationAuditContract::class, SessionConfirmationAudit::class);
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

        // `current_password:web` vergleicht den Hash direkt und fragt weder
        // `authenticateUsing` noch `confirmPasswordsUsing` — für die Fortify-Actions
        // bliebe die Abschaltung damit wirkungslos. Diese Regel steht in ihren
        // Regelketten HINTER `current_password`: Sie kommt erst zum Zug, wenn das
        // Passwort gepasst hat, und ihr Audit-Eintrag trägt damit dieselbe Aussage
        // wie der des Login-Pfads. Der Guard ist derselbe, den jene Ketten benennen.
        Validator::extend('password_login_enabled', static function (): bool {
            $user = Auth::guard('web')->user();

            return $user instanceof User && !$user->isPasswordLoginDisabled();
        });

        // An denselben Konten tritt die frisch bestätigte Sitzung an die Stelle
        // des Passworts: Sie lässt sich dort nur per Passkey erlangen, weil
        // `confirmPasswordsUsing` das Passwort abweist. `extendImplicit`, weil
        // die Regel gerade dann greifen muss, wenn das Feld fehlt. Gerechnet
        // wird wie in `password.confirm` und Jetstreams `ConfirmsPasswords`,
        // damit Formular und Middleware dieselbe Frist kennen.
        Validator::extendImplicit('recently_confirmed', static function (): bool {
            $confirmedAt = Session::get('auth.password_confirmed_at', 0);

            return is_numeric($confirmedAt)
                && (time() - (int) $confirmedAt) < Config::integer('auth.password_timeout');
        });

        // Re-Auth-Failures vor 2FA-Endpoints sichtbar machen. Beide vendor-
        // seitigen Pfade — der Web-Form-Pfad über `ConfirmablePasswordController`
        // und der Livewire-Pfad über Jetstreams `ConfirmsPasswords::confirmPassword()`
        // — laufen durch `Laravel\Fortify\Actions\ConfirmPassword`. Wird hier ein
        // Callback registriert, delegiert die Action an ihn (siehe
        // `vendor/laravel/fortify/src/Actions/ConfirmPassword.php`), und wir
        // fangen beide Pfade an einer Stelle ab. Hash-Vergleich parität zur
        // `authenticateUsing`-Override oben.
        Fortify::confirmPasswordsUsing(static function (User $user, ?string $password): bool {
            // Ohne Passwort kein Vergleich und damit kein Mismatch: Der
            // Fortify-Controller reicht das Feld ungeprüft durch, ein direkter
            // POST ohne Passwort landet also als `null` hier. Das zu auditieren
            // hieße, den Hijack-Indikator ohne einen einzigen Rateversuch
            // auslösbar zu machen. Dieselbe Trennung wie in
            // `DetectsFailedCurrentPassword`.
            if (!is_string($password)) {
                return false;
            }

            // Der Hash-Vergleich zuerst, wie in den Regelketten der Fortify-Actions
            // und im Login-Pfad: `password_login_disabled` belegt dort ein genanntes
            // Passwort. Stünde diese Prüfung davor, trüge dieselbe Aussage auch jeder
            // blinde Rateversuch, und das Raten verlöre seinen eigenen Grund an genau
            // den Konten, an denen er zählt.
            if (!Hash::check($password, $user->password)) {
                app(SessionConfirmationAuditContract::class)
                    ->recordPasswordFailure($user, ActivityFailureReason::CURRENT_PASSWORD_MISMATCH);

                return false;
            }

            // Der abgeschaltete Passwort-Login nimmt dem Passwort auch hier die
            // Wirkung. Ohne diese Zeile bliebe es der Schlüssel zur Passkey-
            // Verwaltung: Wer es abgephisht hat und an eine Sitzung kommt, stünde
            // vor derselben Hürde wie vor dem Abschalten.
            if ($user->isPasswordLoginDisabled()) {
                app(SessionConfirmationAuditContract::class)
                    ->recordPasswordFailure($user, ActivityFailureReason::PASSWORD_LOGIN_DISABLED);

                return false;
            }

            return true;
        });

        Fortify::authenticateUsing(static function (Request $request): ?User {
            /** @var string $email */
            $email = $request->input('email');

            /** @var string $password */
            $password = $request->input('password');

            $user = User::queryByEmail($email)->first();

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
                $unapprovedLoginContext = app(UnapprovedLoginContextContract::class);

                // Marker VOR dem Audit-Schreiben setzen: Selbst wenn der
                // Audit-Insert wirft, soll der nachgelagerte `Failed`-Event
                // unterdrückt bleiben — sonst rauschte trotzdem ein doppelter
                // `login_failed`-Eintrag.
                $unapprovedLoginContext->markActive();
                $unapprovedLoginContext->record($user, 'web', $email);

                return null;
            }

            // Nach der Freischaltung geprüft, damit ein noch nicht freigeschaltetes
            // Konto den fachlich grundlegenderen `login_unapproved`-Eintrag behält.
            if ($user->isPasswordLoginDisabled()) {
                $disabledPasswordLoginContext = app(DisabledPasswordLoginContextContract::class);

                $disabledPasswordLoginContext->markActive();
                $disabledPasswordLoginContext->record($user, 'web', $email);

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
