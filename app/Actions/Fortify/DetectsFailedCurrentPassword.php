<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Validation\ValidationException;

trait DetectsFailedCurrentPassword
{
    /**
     * Prüfung über `failed()` (verletzte Rule) statt bloßer Key-Existenz in
     * `errors()`: Ein `required`-Verstoß (Feld fehlt bei direktem HTTP-Call)
     * ist kein geprüfter Mismatch und darf nicht als solcher auditiert werden.
     * `CurrentPassword` ist der Basename von Fortifys `current_password`-Rule.
     */
    protected function currentPasswordRuleFailed(ValidationException $e): bool
    {
        $failedRules = $e->validator->failed()['current_password'] ?? [];

        return is_array($failedRules) && array_key_exists('CurrentPassword', $failedRules);
    }
}
