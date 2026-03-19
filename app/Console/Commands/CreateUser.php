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
        $title = __('commands.create_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $name = $this->ask(__('commands.create_user.ask_name'));
        $email = $this->ask(__('commands.common.ask_email'));
        $password = $this->secret(__('commands.create_user.ask_password'));
        $passwordConfirm = $this->secret(__('commands.create_user.ask_password_confirm'));

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

        $this->info(__('commands.create_user.success', ['name' => $name, 'email' => $email]));

        return self::SUCCESS;
    }
}
