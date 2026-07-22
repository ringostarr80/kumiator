<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use App\Services\User\Contracts\UserApproverContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:approve')]
#[Description('Schaltet einen Benutzer frei')]
class Approve extends Command
{
    public function __construct(private readonly UserApproverContract $approver)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.approve_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if ($user->approved_at !== null) {
            $this->warn(__('commands.approve_user.already_approved', ['name' => $user->name, 'email' => $email]));

            return self::SUCCESS;
        }

        $this->approver->approve($user);

        $this->info(__('commands.approve_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
