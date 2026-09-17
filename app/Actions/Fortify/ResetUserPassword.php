<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\ActivityFailureReason;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Spatie\Activitylog\Facades\Activity;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param array<string, string> $input
     * @throws \Illuminate\Validation\ValidationException
     */
    public function reset(User $user, array $input): void
    {
        // Zweite Linie hinter dem unterdrückten Versand: Ein Link, der vor dem
        // Abschalten verschickt wurde, lebt bis zu einer Stunde weiter. Das damit
        // gesetzte Passwort taugte zwar nicht mehr zur Anmeldung, wohl aber zur
        // Passwortbestätigung — und damit zur Übernahme der Passkey-Verwaltung.
        if ($user->isPasswordLoginDisabled()) {
            // Der Broker löscht den Token erst hinter diesem Callback, den die
            // Exception vorzeitig verlässt. Ungelöscht überstünde der abgewehrte
            // Link die Abschaltung und griffe, sobald der Passwort-Login wieder
            // offen ist.
            Password::deleteToken($user);

            $this->recordRefusedReset($user);

            throw ValidationException::withMessages([
                'email' => [__('passwords.disabled')],
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }

    /**
     * Wer den Link einlöst, hat Zugriff auf das Postfach des Kontos — ein
     * stärkeres Signal als das gekannte Passwort, das der Login-Pfad als
     * `login_password_disabled` festhält. Ohne den Eintrag bräche der Trail nach
     * der Anforderung des Links ab.
     *
     * Kein resilientes `try/catch`: Der erfolgreiche Reset protokolliert über den
     * `PasswordReset`-Event ebenso ungeschützt, beide Ausgänge haben damit
     * dieselben Garantien.
     */
    private function recordRefusedReset(User $user): void
    {
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_RESET_FAILED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['failure_reason' => ActivityFailureReason::PASSWORD_LOGIN_DISABLED->value])
            ->log('');
    }
}
