<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(private readonly UserEmailChangerContract $emailChanger)
    {
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
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo']) && $input['photo'] instanceof UploadedFile) {
            $user->updateProfilePhoto($input['photo']);
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
