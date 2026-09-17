<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\ActivityFailureReason;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait DetectsFailedCurrentPassword
{
    /**
     * Der abgeschaltete Passwort-Login streicht den Nachweis nicht, er tauscht
     * ihn aus: Ohne nutzbares Passwort tritt die per Passkey bestätigte Sitzung
     * an die Stelle des Passworts. Sie trägt die Freigabe allein, ein daneben
     * gesendetes Passwort fügt ihr nichts hinzu — geprüft wird es deshalb nur
     * ohne sie. Sonst fiele der Eigentümer über sein eigenes Feld: Es steht
     * nach dem Abschalten noch im alten Seitenaufbau, und sein Inhalt landete
     * als abgephishtes Passwort im Audit-Log. Unbestätigt behält das Passwort
     * seine Prüfung — dort ist es das Signal, dass jemand es ausprobiert.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    protected function currentPasswordRules(User $user, array $input): array
    {
        if (!$user->isPasswordLoginDisabled()) {
            return ['bail', 'required', 'string', 'current_password:web'];
        }

        if (($input['current_password'] ?? '') === '' || $this->sessionWasRecentlyConfirmed()) {
            return ['recently_confirmed'];
        }

        return ['bail', 'string', 'current_password:web', 'password_login_enabled'];
    }

    /**
     * Prüfung über `failed()` (verletzte Rule) statt bloßer Key-Existenz in
     * `errors()`: Ein `required`-Verstoß (Feld fehlt bei direktem HTTP-Call)
     * ist kein geprüfter Mismatch und darf nicht als solcher auditiert werden.
     * `CurrentPassword` ist der Basename von Fortifys `current_password`-Rule,
     * `PasswordLoginEnabled` der unserer eigenen `password_login_enabled`-Rule.
     *
     * Die Regelkette prüft in dieser Reihenfolge und bricht per `bail` beim
     * ersten Fehlschlag ab: Wer diese Regel auslöst, hat das gültige Passwort
     * eines Kontos genannt, das sich damit nicht mehr anmelden darf.
     */
    protected function failedCurrentPasswordReason(ValidationException $e): ?ActivityFailureReason
    {
        $failedRules = $e->validator->failed();

        if (Arr::has($failedRules, 'current_password.CurrentPassword')) {
            return ActivityFailureReason::CURRENT_PASSWORD_MISMATCH;
        }

        return Arr::has($failedRules, 'current_password.PasswordLoginEnabled')
            ? ActivityFailureReason::PASSWORD_LOGIN_DISABLED
            : null;
    }

    /**
     * Fragt die Regel, die über das leere Feld entscheidet, statt ihre Frist
     * ein zweites Mal auszurechnen: Sie ist implicit und urteilt allein über
     * die Sitzung, ein leerer Datensatz genügt ihr.
     */
    private function sessionWasRecentlyConfirmed(): bool
    {
        return Validator::make([], ['current_password' => ['recently_confirmed']])->passes();
    }
}
