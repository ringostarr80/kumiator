<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\Contracts\ExistingAccountNotifierContract;
use App\Services\Auth\Contracts\NewAccountNotifierContract;
use App\Services\Auth\Contracts\SelfRegistrarContract;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Laravel\Jetstream\Jetstream;

/**
 * Hinter einem eigenen Contract statt hinter Fortifys `CreatesNewUsers`:
 * Fortifys Registrierung meldet das neue Konto sofort an und verriete damit,
 * ob die Adresse frei war. Validierung und Anlage bleiben in dieser Action,
 * weil sie Jetstream brauchen, das ein Service nicht kennen darf.
 *
 * Ohne Passwort: Wer sich registriert, muss nicht der Inhaber der Adresse
 * sein, und dessen Bestätigung gälte sonst auch für ein fremdes Passwort.
 * Festgelegt wird es deshalb erst über den Link aus dem Postfach.
 */
class CreateNewUser implements SelfRegistrarContract
{
    public function __construct(
        private readonly SelfRegistrationContextContract $selfRegistrationContext,
        private readonly ExistingAccountNotifierContract $existingAccountNotifier,
        private readonly NewAccountNotifierContract $newAccountNotifier,
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
            'name' => ['required', 'string', 'max:255', User::NAME_CHARACTERS_RULE],
            'email' => ['required', 'string', 'email', 'max:255'],
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

        // `withTrashed()`: Auch ein ausgetretenes Konto hält die Adresse, die
        // Anlage scheiterte sonst am Unique-Index.
        $existingUser = User::queryByEmail($email)->withTrashed()->first();

        if ($existingUser !== null) {
            // Timing-Angleichung für den Fall, dass die Timebox nicht reicht:
            // Die Anlage kostet einen Hash-Lauf, ohne ihn antwortete eine
            // vergebene Adresse dann messbar schneller.
            User::unknownPasswordHash();

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
            $user = DB::transaction(static function () use ($input, $email): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $email,
                    // Gelten soll erst das über den Link festgelegte Passwort.
                    'password' => User::unknownPasswordHash(),
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

        $this->newAccountNotifier->notify($user);
    }
}
