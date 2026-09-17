<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\Enums\ActivityFailureReason;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\Contracts\SessionConfirmationAuditContract;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use App\Services\WebAuthn\Exceptions\CredentialOwnerMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Webauthn\Exception\CounterException;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Bestätigt eine bestehende Sitzung per Passkey, wo sonst das Passwort abgefragt
 * würde.
 *
 * Der einzige Anker der Bestätigung ist `auth.password_confirmed_at` in der
 * Session — dieselbe Stelle, die `Illuminate\Auth\Middleware\RequirePassword` und
 * Jetstreams `ConfirmsPasswords` lesen. Wer sie nach geprüfter Assertion setzt,
 * bedient beide Pfade, ohne sie anzufassen.
 *
 * Endpunkte:
 *   GET /user/passkeys/confirm/options – erzeugt und liefert die Request-Optionen
 *   POST /user/passkeys/confirm – prüft die Assertion und setzt die Bestätigung
 */
final class PasskeyConfirmationController extends Controller
{
    /** Eigener Schlüssel, damit eine parallel laufende Anmeldezeremonie nicht überschrieben wird. */
    private const SESSION_KEY = 'webauthn.confirmation.options';

    public function __construct(
        private readonly PasskeyAuthenticationContract $authenticationService,
        private readonly WebAuthnCeremonySessionContract $ceremonySession,
        private readonly SessionConfirmationAuditContract $audit,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        $options = $this->authenticationService->createConfirmationOptions($this->currentUser());

        // Ohne eigenen Passkey bleibt `allowCredentials` leer, und leer lässt die
        // Zeremonie ihre Credential-Prüfung als ersten Schritt aus: Ein fremder
        // Passkey liefe dann bis zur Signaturprüfung durch, ehe der
        // Eigentümervergleich ihn abweist. Ein Konto mit eigenen Passkeys ist
        // davor geschützt, dieses hätte hier ohnehin nichts zu bestätigen.
        if ($options->allowCredentials === []) {
            return response()->json(
                ['message' => __('app.passkey_confirmation_no_passkey')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json($this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request));
    }

    /**
     * Der Request-Body muss das rohe JSON des Browsers sein. Bei Erfolg steht die
     * Bestätigung in der Session und in der Antwort eine Redirect-URL.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->currentUser();

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
            $this->authenticationService->verify(
                rawResponse: $rawResponse,
                storedOptions: $storedOptions,
                host: WebauthnConfig::effectiveHost(),
                identifiedUser: $user,
            );
        } catch (WebauthnException $e) {
            // Die Oberklasse statt der Einzeltypen: Jede von ihr abgeleitete
            // Exception sagt, dass die Antwort des Browsers die Zeremonie nicht
            // bestanden hat. Ein Serverfehler ist das nie — und eine Aufzählung
            // einzelner Typen liefe mit der nächsten Version der Bibliothek
            // wieder auseinander.
            // Zwei Fehlschläge tragen einen Grund, weil sie eine andere Reaktion
            // verlangen als ein weiterer Versuch: der stehengebliebene Zähler eines
            // geklonten Authenticators und die Credential-ID eines fremden Kontos.
            $reason = match (true) {
                $e instanceof CounterException => ActivityFailureReason::COUNTER_INVALID,
                $e instanceof CredentialOwnerMismatchException => ActivityFailureReason::CREDENTIAL_OWNER_MISMATCH,
                default => null,
            };

            return $this->reject($user, $reason, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->audit->recordPasskeyFailure($user, ActivityFailureReason::INTERNAL_ERROR);

            return response()->json(
                ['message' => __('app.passkey_confirmation_server_error')],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $request->session()->passwordConfirmed();

        $this->audit->recordPasskeySuccess($user);

        // Die Vollseiten-Variante der Bestätigung braucht ein Ziel: Dorthin
        // geschickt hat den Nutzer die Middleware, und nur sie kennt die
        // ursprünglich angesteuerte Adresse. Nur sie fragt deshalb danach —
        // `intended()` zieht das Ziel aus der Sitzung, und nähme der Profildialog
        // es mit, landete eine noch wartende Bestätigungsseite auf der Startseite.
        $home = Config::string('fortify.home', '/dashboard');
        $redirect = $request->boolean('intended')
            ? redirect()->intended($home)
            : redirect()->to($home);

        return response()->json(['redirect' => $redirect->getTargetUrl()]);
    }

    private function currentUser(): User
    {
        return Auth::user() ?? abort(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Ein einziger Antworttext für jeden Fehlschlag: Ob die Signatur nicht passte
     * oder der Passkey einem anderen Konto gehört, ändert nichts daran, was der
     * Aufrufer als Nächstes tun kann. Der Grund bleibt dem Log vorbehalten, und
     * auch dort nur, wo er eine andere Reaktion nach sich zieht als „nochmal
     * versuchen". Der Wortlaut der Bibliothek geht immer mit ins Log: Nur er
     * sagt bei einer Serie von Fehlschlägen, an welchem Schritt sie bricht.
     */
    private function reject(User $user, ?ActivityFailureReason $reason, string $detail): JsonResponse
    {
        $this->audit->recordPasskeyFailure($user, $reason, $detail);

        return response()->json(
            ['message' => __('app.passkey_confirmation_failed')],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
