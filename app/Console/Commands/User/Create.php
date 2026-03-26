<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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

        $name = $this->ask(__('commands.create_user.ask_name'));
        $email = $this->ask(__('commands.common.ask_email'));
        $password = $this->secret(__('commands.create_user.ask_password'));
        $passwordConfirm = $this->secret(__('commands.create_user.ask_password_confirm'));

        $roleName = $this->choice(__('commands.create_user.ask_role'), $roles);
        assert(is_string($roleName));

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
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

        DB::transaction(static function () use ($name, $email, $password, $roleName): void {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            $user->assignRole($roleName);
        });

        $this->info(__('commands.create_user.success', ['name' => $name, 'email' => $email]));

        return self::SUCCESS;
    }
}
