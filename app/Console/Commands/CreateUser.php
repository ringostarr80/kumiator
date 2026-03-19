<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('user:create')]
#[Description('Legt einen neuen Benutzer an')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Neuen Benutzer anlegen');
        $this->line('----------------------------');

        $name = $this->ask('Name');
        $email = $this->ask('E-Mail');
        $password = $this->secret('Passwort');
        $passwordConfirm = $this->secret('Passwort bestätigen');

        $validator = Validator::make(
            [
                'name'                  => $name,
                'email'                 => $email,
                'password'              => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'name'     => ['required', 'string', 'max:255'],
                'email'    => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        assert(is_string($name));
        assert(is_string($email));
        assert(is_string($password));

        User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Benutzer \"$name\" ($email) wurde erfolgreich angelegt.");

        return self::SUCCESS;
    }
}
