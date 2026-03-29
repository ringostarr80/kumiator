<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:delete')]
#[Description('Löscht einen bestehenden Benutzer')]
class Delete extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.delete_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        /** @var ?User $user */
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.delete_user.user_found', ['name' => $user->name, 'email' => $email]));

        if (!$this->confirm(__('commands.delete_user.confirm_delete'))) {
            $this->info(__('commands.delete_user.aborted'));

            return self::SUCCESS;
        }

        $user->deleteOrFail();

        $this->info(__('commands.delete_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
