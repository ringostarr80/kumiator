<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use Tests\TestCase;

final class AccountAlreadyExistsNotificationTest extends TestCase
{
    public function testMailGreetsTheOwnerAndLeadsToTheLogin(): void
    {
        $user = User::factory()->make(['name' => 'Erika']);

        $mail = (new AccountAlreadyExistsNotification())->toMail($user);

        $this->assertSame(__('app.account_exists_subject'), $mail->subject);
        $this->assertSame(__('app.mail_greeting', ['name' => 'Erika']), $mail->greeting);
        $this->assertContains(__('app.account_exists_intro'), $mail->introLines);
        $this->assertSame(route('login'), $mail->actionUrl);
    }
}
