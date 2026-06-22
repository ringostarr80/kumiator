<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Spatie\Activitylog\Facades\Activity;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * @param array<string, string> $input
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(User $user, array $input): void
    {
        try {
            Validator::make($input, [
                'current_password' => ['required', 'string', 'current_password:web'],
                'password' => $this->passwordRules(),
            ])->validateWithBag('updatePassword');
        } catch (ValidationException $e) {
            $this->recordFailedCurrentPasswordCheck($user, $e);

            throw $e;
        }

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->saveOrFail();

        // Jetstreams `UpdatePasswordForm` ruft diese Action direkt auf und
        // feuert das Fortify-Event nicht — also hier dispatchen, damit der
        // `LogAuthenticationActivityListener` einen Eintrag schreibt.
        event(new PasswordUpdatedViaController($user));
    }

    /**
     * Schreibt einen `password_update_failed`-Eintrag, wenn die
     * `current_password`-Regel verletzt wurde. Reine Passwort-Rule-Fehler
     * (zu kurz, Confirmation-Mismatch) sind UX-Eingabefehler ohne
     * Sicherheitssignal und bleiben bewusst ungeloggt — nur der forensisch
     * relevante Mismatch des aktuellen Passworts wird festgehalten (Indiz
     * für Session-Hijacking, fremder Nutzer am Endgerät, Shoulder-Surfing).
     *
     * Bewusst KEIN resilienter `try/catch`: Der Erfolgs-Pfad (das Loggen des
     * erfolgreichen Passwortwechsels) verfährt symmetrisch ohne Resilienz. Ein
     * Activity-Log-DB-Fehler würde also auch im Erfolgsfall die Antwort
     * verderben — der Failure-Pfad bekommt damit identische Garantien.
     */
    private function recordFailedCurrentPasswordCheck(User $user, ValidationException $e): void
    {
        // Prüfung über `failed()` (verletzte Rule) statt bloßer Key-Existenz
        // in `errors()`: ein `required`-Verstoß (Feld fehlt bei direktem
        // HTTP-Call) ist kein geprüfter Mismatch und darf nicht als solcher
        // auditiert werden.
        $failedRules = $e->validator->failed()['current_password'] ?? [];

        if (!is_array($failedRules) || !array_key_exists('CurrentPassword', $failedRules)) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_UPDATE_FAILED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['failure_reason' => 'current_password_mismatch'])
            ->log(ActivityEvent::PASSWORD_UPDATE_FAILED->description());
    }
}
