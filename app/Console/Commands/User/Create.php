<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Console\Commands\Command;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

#[Description('Legt einen neuen Benutzer an')]
#[Signature('user:create')]
class Create extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.create_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var list<string> $roles */
        $roles = Role::orderBy('name')->pluck('name')->toArray();

        if ($roles === []) {
            $this->error(__('commands.create_user.no_roles'));

            return self::FAILURE;
        }

        /** @var string $name */
        $name = $this->ask(__('commands.create_user.ask_name'));
        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        // Zweimal, weil das Konto schon freigeschaltet ist: Bei einem Tippfehler
        // übernähme es der Inhaber dieser Adresse per Reset samt Rolle.
        /** @var string $emailConfirm */
        $emailConfirm = $this->ask(__('commands.create_user.ask_email_confirm')) ?? '';
        // Vor der `unique`-Regel: sie vergleicht den rohen Eingabewert gegen die
        // Spalte, sähe den Mutator also nie und ließe ein Nicht-ASCII-Duplikat
        // erst am Unique-Index auflaufen. Die Wiederholung ebenso, sonst wiese
        // `confirmed` eine Abweichung in der Schreibweise ab.
        $email = User::normalizeEmail($email);
        $emailConfirm = User::normalizeEmail($emailConfirm);
        /** @var string $password */
        $password = $this->secret(__('commands.create_user.ask_password'));
        $passwordConfirm = $this->secret(__('commands.create_user.ask_password_confirm'));

        /** @var string $roleName */
        $roleName = $this->choice(__('commands.create_user.ask_role'), $roles);

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'email_confirmation' => $emailConfirm,
                'password' => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    User::NAME_CHARACTERS_RULE,
                ],
                'email' => ['required', 'email', 'unique:users,email', 'confirmed'],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(static function () use ($name, $email, $password, $roleName): void {
            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $user->saveOrFail();

            // Wer auf der Konsole anlegt, hat über die Freischaltung schon
            // entschieden. Die Adresse bleibt unbestätigt: Eingetippt ist sie
            // noch nicht belegt. Erst nach dem Anlegen gespeichert, damit die
            // Freischaltung wie nach `user:approve` als `user_approved` im
            // Audit steht.
            $user->approved_at = Carbon::now();
            $user->saveOrFail();

            $user->assignRole($roleName);
        });

        $this->info(__('commands.create_user.success', ['name' => $name, 'email' => $email]));

        return self::SUCCESS;
    }
}
