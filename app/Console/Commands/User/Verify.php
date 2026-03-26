<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('user:verify')]
#[Description('Verifiziert die E-Mail-Adresse eines Benutzers')]
class Verify extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.verify_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->warn(__('commands.verify_user.already_verified', ['name' => $user->name, 'email' => $email]));

            return self::SUCCESS;
        }

        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->info(__('commands.verify_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
