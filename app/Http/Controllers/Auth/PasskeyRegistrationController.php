<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\PasskeyRegistrationService;
use App\Services\WebAuthn\WebAuthnServerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Handles the passkey registration ceremony for authenticated users.
 *
 * Endpoints:
 *   GET /user/passkeys/register/options – generate and return creation options
 *   POST /user/passkeys/register – verify browser response and persist
 *   DELETE /user/passkeys/{id} – remove a stored passkey
 */
class PasskeyRegistrationController extends Controller
{
    use AuthorizesRequests;
    use NormalizesWebAuthnJson;

    /** Session key used to store the pending creation options. */
    private const SESSION_KEY = 'webauthn.registration.options';

    public function __construct(
        private readonly PasskeyRegistrationService $registrationService,
        private readonly WebAuthnServerService $serverService,
        private readonly PasskeyCredentialRepository $repository,
    ) {
    }

    /**
     * Generate PublicKeyCredentialCreationOptions and store them in the session.
     * Returns JSON that the browser passes to navigator.credentials.create().
     */
    public function options(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!($user instanceof User)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $options = $this->registrationService->createOptions($user);

        // Serialise with the WebAuthn serializer so that binary fields are
        // properly Base64URL-encoded and the JSON matches the W3C spec.
        $serializer = $this->serverService->getSerializer();
        $json = $serializer->serialize($options, 'json');

        // Persist the options in the session so that verify() can compare the
        // challenge.  We store the serialised JSON and deserialise on the way back.
        $request->session()->put(self::SESSION_KEY, $json);

        return response()->json(self::stripNulls(json_decode($json, true)));
    }

    /**
     * Verify the browser's attestation response and persist the new passkey.
     */
    public function store(Request $request): JsonResponse
    {
        $optionsJson = $request->session()->pull(self::SESSION_KEY);

        if ($optionsJson === null) {
            return response()->json(
                ['message' => __('app.passkey_registration_session_expired')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rawResponse = $request->getContent();

        if ($rawResponse === '') {
            return response()->json(['message' => __('app.passkey_empty_request')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $serializer = $this->serverService->getSerializer();
            $storedOptions = $serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');

            $credentialName = $request->input('name', '');
            $credentialName = is_string($credentialName)
                ? $credentialName
                : '';
            $credentialName = trim($credentialName) !== ''
                ? trim($credentialName)
                : __('app.passkey_default_name');

            $passkeyCredential = $this->registrationService->verifyAndSave(
                rawResponse: $rawResponse,
                storedOptions: $storedOptions,
                credentialName: $credentialName,
                host: $request->getHost(),
            );
        } catch (AuthenticatorResponseVerificationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(
                ['message' => __('app.passkey_registration_failed')],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'id' => $passkeyCredential->id,
            'name' => $passkeyCredential->name,
            'created_at' => $passkeyCredential->created_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Delete a passkey. Only the owning user may delete their own passkeys.
     */
    public function destroy(PasskeyCredential $passkeyCredential): JsonResponse
    {
        $this->authorize('delete', $passkeyCredential);

        $this->repository->delete($passkeyCredential);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
