<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Config\WebAuthnConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasskeyStoreRequest;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Services\WebAuthn\Contracts\PasskeyRegistrationContract;
use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
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
final class PasskeyRegistrationController extends Controller
{
    use AuthorizesRequests;

    /** Session key used to store the pending creation options. */
    private const SESSION_KEY = 'webauthn.registration.options';

    public function __construct(
        private readonly PasskeyRegistrationContract $registrationService,
        private readonly WebAuthnCeremonySessionContract $ceremonySession,
        private readonly PasskeyCredentialRepositoryContract $repository,
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

        return response()->json($this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request));
    }

    /**
     * Verify the browser's attestation response and persist the new passkey.
     */
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

        $user = Auth::user();

        if (!($user instanceof User)) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

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
                host: WebAuthnConfig::effectiveHost(),
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

        $request->session()->regenerate();

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
