<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\Contracts\ExistingAccountNotifierContract;
use App\Services\Auth\Contracts\SelfRegistrarContract;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Laravel\Jetstream\Jetstream;

/**
 * Hinter einem eigenen Contract statt hinter Fortifys `CreatesNewUsers`:
 * Fortifys Registrierung meldet das neue Konto sofort an und verriete damit,
 * ob die Adresse frei war. Validierung und Anlage bleiben in dieser Action,
 * weil sie Jetstream und die gemeinsamen Passwortregeln brauchen, die ein
 * Service nicht kennen darf.
 */
class CreateNewUser implements SelfRegistrarContract
{
    use PasswordValidationRules;

    public function __construct(
        private readonly SelfRegistrationContextContract $selfRegistrationContext,
        private readonly ExistingAccountNotifierContract $existingAccountNotifier,
        private readonly Timebox $timebox,
    ) {
    }

    /**
     * @param array<mixed> $input
     */
    public function register(array $input): void
    {
        // Die Spalte hält nur die Normalform; die Anlage unten muss sie
        // selbst herstellen.
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ])->validate();

        // Eine freie, eine vergebene und eine ausgetretene Adresse schreiben
        // verschieden viel in die Datenbank; ohne Mindestdauer verriete das die
        // Antwortzeit. Die Validierung hängt nicht an der Adresse und bleibt
        // draußen, damit ein Tippfehler sofort gemeldet wird.
        $this->timebox->call(function () use ($input): void {
            $this->createOrNotify($input);
        }, Config::integer('auth.timebox_duration'));
    }

    /**
     * @param array<mixed> $input
     */
    private function createOrNotify(array $input): void
    {
        /** @var string $email */
        $email = $input['email'];

        /** @var string $password */
        $password = $input['password'];

        // `withTrashed()`: Auch ein ausgetretenes Konto hält die Adresse, die
        // Anlage scheiterte sonst am Unique-Index.
        $existingUser = User::queryByEmail($email)->withTrashed()->first();

        if ($existingUser !== null) {
            // Timing-Angleichung für den Fall, dass die Timebox nicht reicht:
            // Die Anlage kostet einen Hash-Lauf, ohne ihn antwortete eine
            // vergebene Adresse dann messbar schneller.
            Hash::make($password);

            $this->existingAccountNotifier->notify($existingUser);

            return;
        }

        // Marker für den `Activity::saving`-Listener im AppServiceProvider:
        // er labelt den durch `User::create()` ausgelösten generischen
        // `user.created`-Eintrag auf `user_self_registered` um, sodass die
        // Web-Self-Registration im Audit-Log scharf vom Admin-CLI-Pfad
        // (`user:create`) abgegrenzt ist. `try/finally` statt einfacher
        // Reihenfolge, damit ein Fehler im DB-Transaction-Pfad den Marker
        // nicht im Folge-Request hängen lässt.
        $this->selfRegistrationContext->markActive();

        try {
            $user = DB::transaction(static function () use ($input, $email, $password): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);

                $user->assignRole('member');

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Eine gleichzeitige Registrierung derselben Adresse kam zwischen
            // Abfrage und Anlage zuvor. Ein 500 verriete, dass die Adresse eben
            // noch frei war. Den Hash hat die Transaktion schon bezahlt.
            $this->existingAccountNotifier->notify(
                User::queryByEmail($email)->withTrashed()->first() ?? throw $exception,
            );

            return;
        } finally {
            $this->selfRegistrationContext->clear();
        }

        event(new Registered($user));
    }
}
