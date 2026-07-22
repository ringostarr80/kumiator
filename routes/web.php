<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\CancelEmailChangeController;
use App\Http\Controllers\Auth\ConfirmEmailChangeController;
use App\Http\Controllers\Auth\PasskeyAuthenticationController;
use App\Http\Controllers\Auth\PasskeyRegistrationController;
use App\Http\Controllers\Auth\ResendEmailVerificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\EnsureFortifyCredentialsAreScalar;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;
use Laravel\Jetstream\Http\Controllers\Livewire\ApiTokenController;
use Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController;

Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

Route::get('/', static fn () => view('welcome'));

// ──────────────────────────────────────────────────────────────────────────────
// Passkey authentication (guests only)
//
// Note: Although these endpoints accept JSON, they are registered under the
// "web" middleware group (via routes/web.php) and therefore covered by
// VerifyCsrfToken. Axios sends the X-XSRF-TOKEN header automatically, so
// CSRF protection is fully active. The JSON Content-Type also prevents
// plain HTML form submissions from cross-origin sites.
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(static function (): void {
    // Returns PublicKeyCredentialRequestOptions JSON for the browser.
    // Rate-limited to slow down e-mail enumeration via the allowCredentials field.
    Route::get('/passkeys/authenticate/options', [PasskeyAuthenticationController::class, 'options'])
        ->middleware('throttle:passkey-authenticate-options')
        ->name('passkeys.authenticate.options');

    // Verifies the assertion and logs the user in
    Route::post('/passkeys/authenticate', [PasskeyAuthenticationController::class, 'authenticate'])
        ->middleware(['throttle:passkey-authenticate', 'max.json.body'])
        ->name('passkeys.authenticate');
});

// ──────────────────────────────────────────────────────────────────────────────
// Email verification action
//
// Bewusst NICHT hinter `auth`: typische Realfälle (User registriert sich am
// Desktop, klickt den Link auf dem Smartphone) würden sonst in eine Sackgasse
// laufen, weil `approved_at = null` den Login blockt. Sicherheit gewährleisten
// die signierte URL (HMAC + APP_KEY + Ablauffrist) und der Hash-Vergleich
// gegen die aktuelle E-Mail-Adresse.
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware(['signed', 'throttle:6,1'])
    ->get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->name('verification.verify');

// ──────────────────────────────────────────────────────────────────────────────
// Resend der Verifizierungs-Mail
//
// Überschreibt Fortifys `verification.send`: der Vendor-Controller versendet
// ohne Audit-Trail. Bewusst NUR hinter `auth` (kein `verified`/`approved`) —
// der Anfordernde ist per Definition noch unverifiziert. Last-registered
// gewinnt über Fortifys gleichnamigen Eintrag (vgl. `verification.verify`-
// und `/user/profile`-Override).
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'throttle:6,1'])
    ->post('/email/verification-notification', ResendEmailVerificationController::class)
    ->name('verification.send');

// ──────────────────────────────────────────────────────────────────────────────
// Deferred email change (guest-zugänglich, Token IST die Berechtigung)
//
// Confirm-Link aus der Verifizierungs-Mail an die NEUE Adresse → Tausch.
// Cancel-Link aus der Hinweis-Mail an die ALTE Adresse → Hijack-Schutz.
// GET liefert nur eine nebenwirkungsfreie Landingpage — Mail-Scanner-Prefetch
// darf weder bestätigen noch abbrechen. Die Aktion läuft über den POST des
// Formular-Buttons. IP-basiertes Rate-Limit gegen Token-Probieren (siehe
// `AppServiceProvider`).
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware('throttle:email-change-link')->group(static function (): void {
    Route::get('/email/change/confirm/{token}', [ConfirmEmailChangeController::class, 'show'])
        ->name('email.change.confirm');
    Route::post('/email/change/confirm/{token}', [ConfirmEmailChangeController::class, 'confirm'])
        ->name('email.change.confirm.perform');
    Route::get('/email/change/cancel/{token}', [CancelEmailChangeController::class, 'show'])
        ->name('email.change.cancel');
    Route::post('/email/change/cancel/{token}', [CancelEmailChangeController::class, 'cancel'])
        ->name('email.change.cancel.perform');
});

// ──────────────────────────────────────────────────────────────────────────────
// Registration-pending status page
//
// Eingeloggter, aber noch nicht freigeschalteter User bekommt hier den
// Hinweis, dass er auf Admin-Freischaltung wartet.
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', config('jetstream.auth_session')])
    ->get('/registration-pending', static fn () => view('auth.registration-pending'))
    ->name('registration.pending');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    'approved',
])->group(static function (): void {
    Route::get('/dashboard', static fn () => view('dashboard'))->name('dashboard');

    // Override Jetstreams `/user/profile`: standardmäßig nur `auth + auth_session`,
    // damit der User Tippfehler in seiner Email selbst korrigieren kann. In
    // diesem Projekt korrigiert das der Admin (siehe docs/manual-tests/),
    // deshalb wird die Route hier mit `verified + approved` neu registriert.
    // Da Routen-Provider von App nach Vendor zuletzt-gewinnt geladen werden,
    // schlägt unsere Variante Jetstreams Eintrag.
    Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');

    // Jetstream registriert `api-tokens.index` (Feature `api`) nur hinter
    // `verified`, nicht `approved`. Ohne diese Re-Registrierung könnte ein
    // verifizierter, aber noch nicht freigeschalteter User Sanctum-Tokens anlegen.
    Route::get('/user/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');

    // ──────────────────────────────────────────────────────────────────────────
    // Fortify state-change overrides
    //
    // Fortify registriert die folgenden Routen standardmäßig nur mit `auth`
    // (kein `verified`, kein `approved`). Über die UI sind sie unerreichbar,
    // sobald `/user/profile` zu ist — aber per direktem HTTP-Call hätten
    // unverified/unapproved User weiter Zugriff. Re-Registrierung hier mit
    // den vollen Toren schließt diese Lücke.
    // ──────────────────────────────────────────────────────────────────────────
    Route::put('/user/profile-information', [ProfileInformationController::class, 'update'])
        ->middleware(EnsureFortifyCredentialsAreScalar::class . ':email,password')
        ->name('user-profile-information.update');
    Route::put('/user/password', [PasswordController::class, 'update'])
        ->name('user-password.update');

    Route::middleware('password.confirm')->group(static function (): void {
        Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::post(
            '/user/confirmed-two-factor-authentication',
            [ConfirmedTwoFactorAuthenticationController::class, 'store'],
        )->name('two-factor.confirm');
        Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');
        Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
            ->name('two-factor.qr-code');
        Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])
            ->name('two-factor.secret-key');
        Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])
            ->name('two-factor.recovery-codes');
        Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
            ->name('two-factor.regenerate-recovery-codes');
    });

    // ──────────────────────────────────────────────────────────────────────────
    // Passkey management (authenticated users)
    // ──────────────────────────────────────────────────────────────────────────
    Route::middleware('throttle:passkey-register')->group(static function (): void {
        // Returns PublicKeyCredentialCreationOptions JSON for the browser
        Route::get('/user/passkeys/register/options', [PasskeyRegistrationController::class, 'options'])
            ->name('passkeys.register.options');

        // Verifies the attestation and stores the new passkey
        Route::post('/user/passkeys/register', [PasskeyRegistrationController::class, 'store'])
            ->middleware('max.json.body')
            ->name('passkeys.register');

        // Removes a passkey
        Route::delete('/user/passkeys/{passkeyCredential}', [PasskeyRegistrationController::class, 'destroy'])
            ->name('passkeys.destroy');
    });

    // ──────────────────────────────────────────────────────────────────────────
    // Admin area
    //
    // Access is gated by granular permissions (via spatie/laravel-permission).
    // Bewusst KEINE `can:activity-log.view`-Route-Middleware: die würde den
    // Request vor dem Mount der Livewire-Komponente abbrechen, sodass der
    // abgelehnte Zugriff un-auditiert bliebe. Autorisierung UND Audit
    // (authorization_denied bei Verweigerung, activity_log_viewed bei Erfolg)
    // laufen daher gebündelt in `ActivityLogTable::mount()` — der einzigen
    // Stelle, die auch im Verweigerungsfall garantiert ausgeführt wird.
    // ──────────────────────────────────────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(static function (): void {
        Route::get('/activity-log', static fn () => view('admin.activity-log'))
            ->name('activity-log');
    });
});
