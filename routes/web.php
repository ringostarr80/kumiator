<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\CancelEmailChangeController;
use App\Http\Controllers\Auth\ConfirmEmailChangeController;
use App\Http\Controllers\Auth\PasskeyAuthenticationController;
use App\Http\Controllers\Auth\PasskeyRegistrationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;
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
// Deferred email change (guest-zugänglich, Token IST die Berechtigung)
//
// Confirm-Link aus der Verifizierungs-Mail an die NEUE Adresse → Tausch.
// Cancel-Link aus der Hinweis-Mail an die ALTE Adresse → Hijack-Schutz.
// Beides via GET, analog zu Laravels Verify-Email-Pattern. IP-basiertes
// Rate-Limit gegen Token-Probieren (siehe `AppServiceProvider`).
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware('throttle:email-change-link')->group(static function (): void {
    Route::get('/email/change/confirm/{token}', ConfirmEmailChangeController::class)
        ->name('email.change.confirm');
    Route::get('/email/change/cancel/{token}', CancelEmailChangeController::class)
        ->name('email.change.cancel');
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
    // The `can:<permission>` middleware leverages Laravel's Gate — Spatie
    // permissions register themselves as Gate abilities automatically.
    // ──────────────────────────────────────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(static function (): void {
        Route::get('/activity-log', static fn () => view('admin.activity-log'))
            ->middleware('can:activity-log.view')
            ->name('activity-log');
    });
});
