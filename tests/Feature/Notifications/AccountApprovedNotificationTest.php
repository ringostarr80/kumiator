<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use Tests\TestCase;

final class AccountApprovedNotificationTest extends TestCase
{
    public function testMailLeadsToTheLoginWithoutUsingTheStoredName(): void
    {
        $user = User::factory()->make(['name' => 'Erika']);

        $mail = (new AccountApprovedNotification())->toMail($user);

        $this->assertSame(__('app.account_approved_subject'), $mail->subject);
        $this->assertSame(__('app.mail_greeting_without_name'), $mail->greeting);
        $this->assertContains(__('app.account_approved_intro'), $mail->introLines);
        $this->assertSame(route('login'), $mail->actionUrl);
        $this->assertContains(__('app.account_approved_password_hint'), $mail->outroLines);
        $this->assertContains(__('app.account_approved_ignore'), $mail->outroLines);
    }
}
