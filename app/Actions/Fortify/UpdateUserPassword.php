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
use Spatie\Activitylog\Facades\Activity;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use DetectsFailedCurrentPassword;
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
                'current_password' => $this->currentPasswordRules($user, $input),
                'password' => $this->passwordRules(),
            ])->validateWithBag('updatePassword');
        } catch (ValidationException $e) {
            $this->recordFailedCurrentPasswordCheck($user, $e);

            throw $e;
        }

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->saveOrFail();
    }

    /**
     * Schreibt einen `password_update_failed`-Eintrag für die beiden forensisch
     * relevanten Fehlschläge: den Mismatch des aktuellen Passworts (Indiz für
     * Session-Hijacking, fremder Nutzer am Endgerät, Shoulder-Surfing) und das
     * korrekte Passwort an einem Konto, das den Passwort-Login abgeschaltet hat
     * — derselbe Befund, den der Login-Pfad als `login_password_disabled`
     * festhält. Reine Passwort-Rule-Fehler (zu kurz, Confirmation-Mismatch)
     * sind UX-Eingabefehler ohne Sicherheitssignal und bleiben ungeloggt.
     *
     * Bewusst KEIN resilienter `try/catch`: Der Erfolgs-Pfad (das Loggen des
     * erfolgreichen Passwortwechsels) verfährt symmetrisch ohne Resilienz. Ein
     * Activity-Log-DB-Fehler würde also auch im Erfolgsfall die Antwort
     * verderben — der Failure-Pfad bekommt damit identische Garantien.
     */
    private function recordFailedCurrentPasswordCheck(User $user, ValidationException $e): void
    {
        $failureReason = $this->failedCurrentPasswordReason($e);

        if ($failureReason === null) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_UPDATE_FAILED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['failure_reason' => $failureReason->value])
            ->log('');
    }
}
