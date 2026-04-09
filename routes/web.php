<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\PasskeyAuthenticationController;
use App\Http\Controllers\Auth\PasskeyRegistrationController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(static function (): void {
    Route::get('/dashboard', static fn () => view('dashboard'))->name('dashboard');

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
});
