<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('user:reset-password')]
#[Description('Setzt das Passwort eines Benutzers neu')]
class ResetPassword extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.reset_password.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $password = $this->secret(__('commands.reset_password.ask_password'));
        $passwordConfirm = $this->secret(__('commands.reset_password.ask_password_confirm'));

        $validator = Validator::make(
            [
                'password' => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        assert(is_string($password));

        $user->password = Hash::make($password);
        $user->save();

        $this->info(__('commands.reset_password.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
