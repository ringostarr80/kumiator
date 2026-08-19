<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\Http\Controllers\Controller;
use App\Models\PasskeyCredential;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Wickelt die Passkey-Anmeldezeremonie (Assertion) für Gäste ab.
 *
 * Endpunkte:
 *   GET /passkeys/authenticate/options – erzeugt und liefert die Request-Optionen
 *   POST /passkeys/authenticate – prüft die Browser-Antwort und meldet an
 */
final class PasskeyAuthenticationController extends Controller
{
    /** Session-Schlüssel für die noch offenen Request-Optionen. */
    private const SESSION_KEY = 'webauthn.authentication.options';

    public function __construct(
        private readonly PasskeyAuthenticationContract $authenticationService,
        private readonly WebAuthnCeremonySessionContract $ceremonySession,
        private readonly UnapprovedLoginContextContract $unapprovedLoginContext,
    ) {
    }

    /**
     * Der Endpunkt kennt keine Eingabe: Die Optionen sind für jeden Aufrufer
     * identisch, damit die Antwort nichts über registrierte Konten verrät.
     */
    public function options(Request $request): JsonResponse
    {
        $options = $this->authenticationService->createOptions();

        return response()->json($this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request));
    }

    /**
     * Der Request-Body muss das rohe JSON des Browsers sein. Bei Erfolg steht in
     * der Antwort eine Redirect-URL.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $storedOptions = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        if ($storedOptions === null) {
            return response()->json(
                ['message' => __('app.passkey_session_expired')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rawResponse = $request->getContent();

        if ($rawResponse === '') {
            return response()->json(['message' => __('app.passkey_empty_request')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $credential = $this->authenticationService->verify(
                rawResponse: $rawResponse,
                storedOptions: $storedOptions,
                host: WebauthnConfig::effectiveHost(),
            );
        } catch (AuthenticatorResponseVerificationException $e) {
            PasskeyCredential::recordFailedLoginActivity('verification_failed', $rawResponse, $e->getMessage());

            // Immer derselbe Text, egal woran die Zeremonie scheiterte: Die Gründe
            // unterscheiden „Credential unbekannt“ von „Signatur falsch“ und verrieten
            // damit im Klartext, was die Fake-Verifikation über den Zeitkanal gerade
            // verbirgt. Der Grund steht nur noch im Forensik-Log.
            return response()->json(
                ['message' => __('app.passkey_auth_error')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            report($e);
            PasskeyCredential::recordFailedLoginActivity('internal_error', $rawResponse);

            return response()->json(
                ['message' => __('app.passkey_authentication_failed')],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $user = $credential->user;

        if ($user === null) {
            // Credential ohne Owner ist ein Integritätsbruch (der FK verhindert
            // ihn praktisch); wie eine fehlgeschlagene Verifikation behandeln.
            PasskeyCredential::recordFailedLoginActivity('verification_failed', $rawResponse, 'orphaned_credential');

            return response()->json(
                ['message' => __('app.passkey_auth_error')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Nur freigeschaltete Konten dürfen sich anmelden.
        // Audit-Eintrag VOR dem 401: hier ist die Identität durch die
        // WebAuthn-Verifikation eindeutig belegt (Counter geprüft, Credential
        // dem User zugeordnet) — der Eintrag ist daher mit Causer/Subject
        // belastbar, anders als bei `passkey_login_failed`.
        if ($user->approved_at === null) {
            $this->unapprovedLoginContext->record($user, 'web', $user->email);

            return response()->json(
                ['message' => __('auth.failed')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // Service kapselt das `Auth::login()` zusammen mit dem
        // Passkey-Login-Marker (verhindert Doppel-Logging im Activity-Log,
        // siehe `LogAuthenticationActivityListener`). Session-Regeneration
        // bleibt im Controller, weil Session-Handling Web-Layer-Sache ist.
        $this->authenticationService->loginAuthenticatedUser($user);

        // Erfolg erst hier protokollieren — nach bestandenem Freischaltungs-Gate
        // und tatsächlichem Login. Symmetrisch zum Passwort-Pfad, der für
        // unapproved Konten ebenfalls keinen Erfolg schreibt.
        $credential->recordSuccessfulLoginActivity();

        $request->session()->regenerate();

        return response()->json(['redirect' => config('fortify.home', '/dashboard')]);
    }
}
