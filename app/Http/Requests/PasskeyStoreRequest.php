<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Prüft den JSON-Body des Endpunkts, der einen neuen Passkey speichert.
 *
 * `name` ist optional: Ist es gesetzt, muss es eine nicht-leere Zeichenkette mit
 * höchstens 80 Zeichen sein (passend zum `maxlength` der Oberfläche). Fehlt es
 * oder ist es leer, greift im Controller der übersetzte Standardname.
 */
final class PasskeyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Den Zugriff regelt die `auth`-Middleware der Route.
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
