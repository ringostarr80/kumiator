<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly SelfRegistrationContextContract $selfRegistrationContext)
    {
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param array<string, string> $input
     */
    public function create(array $input): User
    {
        // Vor der `unique`-Regel, die den rohen Eingabewert gegen die Spalte
        // vergleicht. Fortifys `lowercase_usernames` senkt die Adresse zwar
        // schon im Controller, ist aber konfigurierbar — die Normalform hängt
        // nicht an einem Vendor-Schalter.
        if (isset($input['email'])) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ])->validate();

        // Marker für den `Activity::saving`-Listener im AppServiceProvider:
        // er labelt den durch `User::create()` ausgelösten generischen
        // `user.created`-Eintrag auf `user_self_registered` um, sodass die
        // Web-Self-Registration im Audit-Log scharf vom Admin-CLI-Pfad
        // (`user:create`) abgegrenzt ist. `try/finally` statt einfacher
        // Reihenfolge, damit ein Fehler im DB-Transaction-Pfad den Marker
        // nicht im Folge-Request hängen lässt.
        $this->selfRegistrationContext->markActive();

        try {
            return DB::transaction(static function () use ($input): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => Hash::make($input['password']),
                ]);

                $user->assignRole('member');

                return $user;
            });
        } finally {
            $this->selfRegistrationContext->clear();
        }
    }
}
