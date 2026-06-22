<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use App\Services\Upload\Exceptions\ProfilePhotoOptimizationException;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Spatie\Activitylog\Facades\Activity;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(
        private readonly UserEmailChangerContract $emailChanger,
        private readonly UploadLimitResolverContract $uploadLimitResolver,
        private readonly ProfilePhotoOptimizerContract $profilePhotoOptimizer,
    ) {
    }

    /**
     * Validate and update the given user's profile information.
     *
     * Der Name wird unmittelbar gespeichert; ein E-Mail-Wechsel löst den
     * zweistufigen Deferred-Flow im `UserEmailChanger`-Service aus — die
     * `email`-Spalte am User wird erst beim Klick auf den Confirm-Link
     * (Mail an die neue Adresse) getauscht. Bis dahin bleibt die alte
     * Adresse für Login + Recovery aktiv (Hijack-Schutz, Tippfehler-Sicherheit).
     *
     * @param array<string, mixed> $input
     */
    public function update(User $user, array $input): void
    {
        $photoMaxKilobytes = $this->uploadLimitResolver->resolveProfilePhotoLimit()->kilobytes();
        $acceptedExtensions = $this->uploadLimitResolver->resolveProfilePhotoAcceptedExtensions();

        // E-Mail-Identität ist case-insensitiv (Spalte mit NOCASE-Collation):
        // Eine reine Schreibweisen-Abweichung der eigenen Adresse ist KEINE
        // Änderung und darf weder Re-Auth noch den Deferred-Flow auslösen.
        $newEmail = $input['email'] ?? null;
        $emailChanged = !is_string($newEmail) || strcasecmp($newEmail, $user->email) !== 0;

        try {
            Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'photo' => ['nullable', 'mimes:' . implode(',', $acceptedExtensions), 'max:' . $photoMaxKilobytes],
                // Re-Auth nur beim E-Mail-Wechsel: Eine gekaperte Session darf
                // den Deferred-Flow nicht anstoßen können — der Confirm-Link
                // ginge an die Angreifer-Adresse, und der erfolgreiche Confirm
                // entwertet den Cancel-Link der alten Adresse sofort.
                'current_password' => $emailChanged
                    ? ['required', 'string', 'current_password:web']
                    : ['nullable', 'string'],
            ], [
                'current_password.required' => __('app.email_change_current_password_required'),
            ])->validateWithBag('updateProfileInformation');
        } catch (ValidationException $e) {
            $this->recordFailedCurrentPasswordCheck($user, $input, $e);

            throw $e;
        }

        if (isset($input['photo']) && $input['photo'] instanceof UploadedFile) {
            // Pfad VOR dem Trait-Aufruf snapshotten — `updateProfilePhoto()`
            // überschreibt `profile_photo_path` per `forceFill()->save()`,
            // danach wäre der alte Wert verloren.
            $previousPath = $user->getAttribute('profile_photo_path');

            // Nicht das Original speichern: der Optimizer rechnet das Foto auf
            // ein quadratisches AVIF-Thumbnail herunter (inkl. EXIF-Korrektur).
            try {
                $optimized = $this->profilePhotoOptimizer->optimize($input['photo']);
            } catch (ProfilePhotoOptimizationException $e) {
                // Pixel-/Decode-/Encode-Fehler bestehen `mimes:`+`max:`, sind aber
                // kein Server-Defekt: Die Maße liegen erst nach dem Decode vor. Als
                // Feld-Validierung am `photo`-Feld melden, statt als HTTP 500
                // durchschlagen zu lassen.
                throw ValidationException::withMessages([
                    'photo' => $e->getMessage(),
                ])->errorBag('updateProfileInformation');
            }

            $user->updateProfilePhoto($optimized);

            $newPath = $user->getAttribute('profile_photo_path');

            Activity::useLog(ActivityChannel::USER->value)
                ->event(ActivityEvent::PROFILE_PHOTO_UPDATED->value)
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'profile_photo_path' => is_string($newPath) ? $newPath : null,
                    'previous_profile_photo_path' => is_string($previousPath) ? $previousPath : null,
                ])
                ->log(ActivityEvent::PROFILE_PHOTO_UPDATED->description());
        }

        $name = $input['name'];
        $email = $input['email'];

        if (!is_string($name) || !is_string($email)) {
            // Validierung oben erzwingt `string` für beide Felder; dieser
            // Zweig dient ausschließlich der Typ-Eingrenzung gegenüber
            // `array<string, mixed>`.
            return;
        }

        $user->forceFill(['name' => $name])->saveOrFail();

        if (strcasecmp($email, $user->email) !== 0) {
            $this->emailChanger->requestChange($user, $email);
        }
    }

    /**
     * Auditiert NUR den Mismatch des aktuellen Passworts (Forensik-Signal für
     * eine gekaperte Session), nicht den `required`-Verstoß — der ist ein
     * UX-Eingabefehler ohne Sicherheitsaussage. Darum die Prüfung über
     * `failed()` (verletzte Regel) statt bloßer Key-Existenz in `errors()`;
     * Audit-Symmetrie zum `password_update_failed`-Event beim Passwort-Wechsel.
     *
     * Das Schreiben delegiert an den `UserEmailChanger`-Service, weil dort
     * alle `email_change_*`-Audits inkl. des E-Mail-Hashings liegen — Actions
     * dürfen den konkreten `AuditEmailHasher` laut Architektur-Regel nicht
     * direkt nutzen.
     *
     * @param array<string, mixed> $input
     */
    private function recordFailedCurrentPasswordCheck(User $user, array $input, ValidationException $e): void
    {
        $failedRules = $e->validator->failed()['current_password'] ?? [];

        if (!is_array($failedRules) || !array_key_exists('CurrentPassword', $failedRules)) {
            return;
        }

        $attemptedEmail = $input['email'] ?? null;

        $this->emailChanger->recordRequestFailed(
            $user,
            is_string($attemptedEmail) ? $attemptedEmail : null,
        );
    }
}
