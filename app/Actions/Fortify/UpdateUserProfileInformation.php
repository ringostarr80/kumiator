<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:' . implode(',', $acceptedExtensions), 'max:' . $photoMaxKilobytes],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo']) && $input['photo'] instanceof UploadedFile) {
            // Pfad VOR dem Trait-Aufruf snapshotten — `updateProfilePhoto()`
            // überschreibt `profile_photo_path` per `forceFill()->save()`,
            // danach wäre der alte Wert verloren.
            $previousPath = $user->getAttribute('profile_photo_path');

            // Nicht das Original speichern: der Optimizer rechnet das Foto auf
            // ein quadratisches AVIF-Thumbnail herunter (inkl. EXIF-Korrektur).
            $user->updateProfilePhoto($this->profilePhotoOptimizer->optimize($input['photo']));

            $newPath = $user->getAttribute('profile_photo_path');

            Activity::useLog('user')
                ->event('profile_photo_updated')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'profile_photo_path' => is_string($newPath) ? $newPath : null,
                    'previous_profile_photo_path' => is_string($previousPath) ? $previousPath : null,
                ])
                ->log(__('app.activity_profile_photo_updated'));
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

        if ($email !== $user->email) {
            $this->emailChanger->requestChange($user, $email);
        }
    }
}
