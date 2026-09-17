<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\Enums\ActivityFailureReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasskeyStoreRequest;
use App\Models\PasskeyCredential;
use App\Services\WebAuthn\Contracts\PasskeyRegistrationContract;
use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Wickelt die Passkey-Registrierung für angemeldete Nutzer ab.
 *
 * Endpunkte:
 *   GET /user/passkeys/register/options – erzeugt und liefert die Creation-Optionen
 *   POST /user/passkeys/register – prüft die Browser-Antwort und speichert
 */
final class PasskeyRegistrationController extends Controller
{
    /** Session-Schlüssel für die noch offenen Creation-Optionen. */
    private const SESSION_KEY = 'webauthn.registration.options';

    public function __construct(
        private readonly PasskeyRegistrationContract $registrationService,
        private readonly WebAuthnCeremonySessionContract $ceremonySession,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        $user = Auth::user() ?? abort(Response::HTTP_UNAUTHORIZED);

        $options = $this->registrationService->createOptions($user);

        return response()->json($this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request));
    }

    public function store(PasskeyStoreRequest $request): JsonResponse
    {
        $storedOptions = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialCreationOptions::class,
            $request,
        );

        if ($storedOptions === null) {
            return response()->json(
                ['message' => __('app.passkey_registration_session_expired')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rawResponse = $request->getContent();

        if ($rawResponse === '') {
            return response()->json(['message' => __('app.passkey_empty_request')], Response::HTTP_BAD_REQUEST);
        }

        $user = Auth::user() ?? abort(Response::HTTP_UNAUTHORIZED);

        try {
            $nameRaw = $request->validated('name');
            $nameInput = is_string($nameRaw)
                ? trim($nameRaw)
                : '';
            $credentialName = $nameInput !== ''
                ? $nameInput
                : __('app.passkey_default_name');

            $passkeyCredential = $this->registrationService->verifyAndSave(
                user: $user,
                rawResponse: $rawResponse,
                storedOptions: $storedOptions,
                credentialName: $credentialName,
                host: WebauthnConfig::effectiveHost(),
            );
        } catch (WebauthnException $e) {
            // Die Oberklasse statt der Einzeltypen: Jede von ihr abgeleitete
            // Exception sagt, dass die Antwort des Browsers das Attestat nicht
            // bestanden hat — schon beim Deserialisieren, nicht erst in der
            // Zeremonie. Ein Serverfehler ist das nie.
            PasskeyCredential::recordFailedRegistrationActivity(
                $user,
                ActivityFailureReason::VERIFICATION_FAILED,
                $e->getMessage(),
            );

            // Die Meldungen der Bibliothek sind englisch und für den Nutzer ohne
            // Handlungswert („Invalid ID"); der Wortlaut bleibt im Forensik-Log.
            return response()->json(
                ['message' => __('app.passkey_registration_failed')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            report($e);
            PasskeyCredential::recordFailedRegistrationActivity($user, ActivityFailureReason::INTERNAL_ERROR);

            return response()->json(
                ['message' => __('app.passkey_registration_server_error')],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $request->session()->regenerate();

        return response()->json([
            'id' => $passkeyCredential->id,
            'name' => $passkeyCredential->name,
            'created_at' => $passkeyCredential->created_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
