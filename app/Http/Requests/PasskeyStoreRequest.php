<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the JSON body for the passkey registration store endpoint.
 *
 * The `name` field is optional. When present it must be a non-empty string
 * of at most 80 characters (matching the maxlength enforced by the UI).
 * When omitted or empty the controller falls back to the localised default
 * name for the new passkey.
 */
final class PasskeyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level middleware (auth) handles access control.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
        ];
    }
}
