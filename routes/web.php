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
// ──────────────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(static function (): void {
    // Returns PublicKeyCredentialRequestOptions JSON for the browser.
    // Intentionally not rate-limited: the endpoint performs only a read-only DB lookup
    // (email → allowCredentials) and returns identical JSON for both known and unknown
    // e-mail addresses, so there is no meaningful information gain for an attacker.
    // The POST /passkeys/authenticate endpoint carries the rate limit that matters.
    Route::get('/passkeys/authenticate/options', [PasskeyAuthenticationController::class, 'options'])
        ->name('passkeys.authenticate.options');

    // Verifies the assertion and logs the user in
    Route::post('/passkeys/authenticate', [PasskeyAuthenticationController::class, 'authenticate'])
        ->middleware('throttle:passkey-authenticate')
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
            ->name('passkeys.register');

        // Removes a passkey
        Route::delete('/user/passkeys/{passkeyCredential}', [PasskeyRegistrationController::class, 'destroy'])
            ->name('passkeys.destroy');
    });
});
