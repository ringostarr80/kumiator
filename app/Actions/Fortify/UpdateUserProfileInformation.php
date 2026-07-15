<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use App\Services\Upload\Exceptions\ProfilePhotoOptimizationException;
use App\Services\Upload\Exceptions\ProfilePhotoStorageException;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Spatie\Activitylog\Facades\Activity;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    use DetectsFailedCurrentPassword;

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

        // Auf die kanonische Form ziehen, bevor irgendetwas sie liest: die
        // `unique`-Regel unten vergleicht den rohen Eingabewert gegen die
        // Spalte, und der Änderungs-Vergleich muss dieselbe Normalform
        // benutzen, die auch gespeichert wird.
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        // Eine reine Schreibweisen-Abweichung der eigenen Adresse ist KEINE
        // Änderung und darf weder Re-Auth noch den Deferred-Flow auslösen.
        $newEmail = $input['email'] ?? null;
        $emailChanged = !is_string($newEmail) || $newEmail !== $user->email;

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

        $optimizedPhoto = null;
        $previousPhotoPath = null;

        if (isset($input['photo']) && $input['photo'] instanceof UploadedFile) {
            // Alten Pfad snapshotten: Die Datei dahinter wird erst NACH einem
            // erfolgreichen Commit gelöscht (s. unten), nicht synchron in der
            // Transaktion — ein Rollback könnte sie sonst nicht wiederherstellen.
            $previousPhotoPath = $this->stringOrNull($user->getAttribute('profile_photo_path'));

            // Optimierung VOR der Transaktion: schlägt der Bomben-/Decode-Schutz
            // an, wird das als Feld-Validierung gemeldet, ohne dafür eine (leere)
            // Transaktion zu öffnen. Nicht das Original speichern: der Optimizer
            // rechnet das Foto auf ein quadratisches AVIF-Thumbnail herunter
            // (inkl. EXIF-Korrektur). Pixel-/Decode-/Encode-Fehler bestehen
            // `mimes:`+`max:`, sind aber kein Server-Defekt: Die Maße liegen erst
            // nach dem Decode vor — als Feld-Fehler melden, nicht als HTTP 500.
            try {
                $optimizedPhoto = $this->profilePhotoOptimizer->optimize($input['photo']);
            } catch (ProfilePhotoOptimizationException $e) {
                throw ValidationException::withMessages([
                    'photo' => $e->getMessage(),
                ])->errorBag('updateProfileInformation');
            }
        }

        $name = $input['name'];
        $email = $input['email'];

        if (!is_string($name) || !is_string($email)) {
            // Validierung oben erzwingt `string` für beide Felder; dieser
            // Zweig dient ausschließlich der Typ-Eingrenzung gegenüber
            // `array<string, mixed>`.
            return;
        }

        // Die neue Foto-Datei VOR der Transaktion auf die Platte legen: ein
        // Datei-Schreibvorgang ist nicht rückrollbar und gehört darum nicht in
        // die DB-Transaktion. In der Transaktion wird nur der Pfad-Zeiger atomar
        // mit Name + E-Mail-Antrag umgelegt.
        $photoDisk = $user->profilePhotoDiskName();
        $newPhotoPath = null;

        if ($optimizedPhoto !== null) {
            $storedPath = $optimizedPhoto->storePublicly('profile-photos', ['disk' => $photoDisk]);

            if ($storedPath === false) {
                // Die Disk läuft auf `throw => false`, ein Schreibfehler (Platte
                // voll, S3 down) liefert also `false`. Nicht still als „kein Foto"
                // behandeln: sonst committen Name/E-Mail, die UI meldet Erfolg und
                // das Foto fehlt kommentarlos. Hart abbrechen (vor der Transaktion,
                // nichts committet), damit der Infra-Fehler als reportete 500 sichtbar wird.
                throw new ProfilePhotoStorageException('Failed to store the profile photo.');
            }

            $newPhotoPath = $storedPath;
        }

        // Denselben Entscheid, der oben die Re-Auth-Regel setzt, an den
        // Deferred-Flow durchreichen (null = keine Änderung) — ein zweiter
        // Vergleich gegen $user->email könnte von der Re-Auth-Pflicht abweichen.
        $emailChangeRequest = $emailChanged
            ? $email
            : null;

        $committed = false;

        try {
            // Atomar: Foto-Pfad, Name und der Deferred-E-Mail-Antrag (samt ihrer
            // Audit-Einträge) committen gemeinsam oder gar nicht. Sonst bliebe bei
            // einem Fehler im E-Mail-Schritt ein bereits gespeicherter und
            // auditierter Foto-Pfad oder ein hängender `pending_email`-Teilzustand
            // zurück. Die Confirm-/Cancel-Mails sind `ShouldQueueAfterCommit` und
            // gehen damit erst nach erfolgreichem Commit raus — ein Rollback
            // verschickt keine Mail für eine nicht persistierte Änderung.
            DB::transaction(function () use (
                $user,
                $name,
                $emailChangeRequest,
                $newPhotoPath,
                $previousPhotoPath,
                &$committed,
            ): void {
                // Sobald der PDO-Commit durch ist, ist die neue Datei die
                // persistierte — ab hier darf der Rollback-Arm sie nicht mehr
                // löschen. Der Callback läuft vor den ShouldQueueAfterCommit-
                // Pushes des E-Mail-Wechsels (früher registriert); scheitert danach
                // ein solcher Push, ist der Commit längst erfolgt.
                DB::afterCommit(function () use (&$committed): void {
                    $committed = true;
                });

                $this->persistProfileChanges(
                    $user,
                    $name,
                    $emailChangeRequest,
                    $newPhotoPath,
                    $previousPhotoPath,
                );
            });
        } catch (\Throwable $e) {
            // Nur bei echtem Rollback aufräumen: zeigt die DB nach dem Fehler
            // wieder auf das alte Foto, muss die neue Datei weg, sonst verwaist
            // sie. Ist der Commit dagegen schon durch und erst ein
            // After-Commit-Seiteneffekt (Mail-Push) gescheitert, bleibt die
            // committete Datei — ihr Pfad ist persistiert.
            if (!$committed && $newPhotoPath !== null) {
                Storage::disk($photoDisk)->delete($newPhotoPath);
            }

            throw $e;
        }

        // Erst nach erfolgreichem Commit: die alte, jetzt ersetzte Datei löschen.
        if ($newPhotoPath !== null && $previousPhotoPath !== null) {
            Storage::disk($photoDisk)->delete($previousPhotoPath);
        }
    }

    /**
     * Schreibt Foto-Pfad, Name und den Deferred-E-Mail-Antrag innerhalb der
     * aufrufenden `update()`-Transaktion — die Atomaritäts- und
     * After-Commit-Begründung steht dort.
     */
    private function persistProfileChanges(
        User $user,
        string $name,
        ?string $emailChangeRequest,
        ?string $newPhotoPath,
        ?string $previousPhotoPath,
    ): void {
        if ($newPhotoPath !== null) {
            $user->forceFill(['profile_photo_path' => $newPhotoPath])->saveOrFail();

            Activity::useLog(ActivityChannel::USER->value)
                ->event(ActivityEvent::PROFILE_PHOTO_UPDATED->value)
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'profile_photo_path' => $newPhotoPath,
                    'previous_profile_photo_path' => $previousPhotoPath,
                ])
                ->log(ActivityEvent::PROFILE_PHOTO_UPDATED->description());
        }

        $user->forceFill(['name' => $name])->saveOrFail();

        if ($emailChangeRequest !== null) {
            $this->emailChanger->requestChange($user, $emailChangeRequest);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value)
            ? $value
            : null;
    }

    /**
     * Auditiert NUR den Mismatch des aktuellen Passworts (Forensik-Signal für
     * eine gekaperte Session), nicht den `required`-Verstoß — der ist ein
     * UX-Eingabefehler ohne Sicherheitsaussage. Audit-Symmetrie zum
     * `password_update_failed`-Event beim Passwort-Wechsel.
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
        if (!$this->currentPasswordRuleFailed($e)) {
            return;
        }

        $attemptedEmail = $input['email'] ?? null;

        $this->emailChanger->recordRequestFailed(
            $user,
            is_string($attemptedEmail) ? $attemptedEmail : null,
        );
    }
}
