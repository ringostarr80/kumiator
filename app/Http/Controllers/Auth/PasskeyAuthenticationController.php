<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasskeyAuthenticateOptionsRequest;
use App\Models\User;
use App\Services\WebAuthn\PasskeyAuthenticationContract;
use App\Services\WebAuthn\WebAuthnCeremonySession;
use App\Services\WebAuthn\WebAuthnConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Handles the passkey authentication (assertion) ceremony for guests.
 *
 * Endpoints:
 *   GET /passkeys/authenticate/options – generate and return request options
 *   POST /passkeys/authenticate – verify browser response and log in
 */
final class PasskeyAuthenticationController extends Controller
{
    /** Session key used to store the pending request options. */
    private const SESSION_KEY = 'webauthn.authentication.options';

    public function __construct(
        private readonly PasskeyAuthenticationContract $authenticationService,
        private readonly WebAuthnCeremonySession $ceremonySession,
    ) {
    }

    /**
     * Generate PublicKeyCredentialRequestOptions and store them in the session.
     *
     * An optional `email` query parameter narrows down the allowed credentials
     * to a specific user, improving UX for non-discoverable passkeys.
     * When omitted, the browser can use any available passkey (discoverable flow).
     */
    public function options(PasskeyAuthenticateOptionsRequest $request): JsonResponse
    {
        $user = null;

        $email = $request->validated()['email'] ?? null;

        if ($email !== null) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->authenticationService->runFakeCredentialLookup();
            }
        }

        $options = $this->authenticationService->createOptions($user);

        return response()->json($this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request));
    }

    /**
     * Verify the browser's assertion response and log in the user.
     *
     * The request body must be the raw JSON from the browser.
     * On success, returns a JSON object with a redirect URL.
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
            $user = $this->authenticationService->verify(
                rawResponse: $rawResponse,
                storedOptions: $storedOptions,
                host: WebAuthnConfig::effectiveHost(),
            );
        } catch (AuthenticatorResponseVerificationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(
                ['message' => __('app.passkey_authentication_failed')],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        // Respect the approval workflow: only approved users may log in
        if ($user->approved_at === null) {
            return response()->json(
                ['message' => __('auth.failed')],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['redirect' => config('fortify.home', '/dashboard')]);
    }
}
