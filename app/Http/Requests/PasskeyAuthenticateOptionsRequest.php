<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query parameters for the passkey authentication options endpoint.
 *
 * The `email` parameter is optional. When present it must be a valid e-mail
 * address so that the controller can look up the user's credentials and
 * populate the `allowCredentials` list (non-discoverable passkey flow).
 * Omitting it triggers the discoverable-credential flow where the browser
 * picks any eligible passkey on its own.
 */
final class PasskeyAuthenticateOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level middleware (guest + throttle) handles access control.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'email'],
        ];
    }
}
