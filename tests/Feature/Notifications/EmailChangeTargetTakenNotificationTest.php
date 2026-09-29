<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\EmailChangeTargetTakenNotification;
use Tests\TestCase;

final class EmailChangeTargetTakenNotificationTest extends TestCase
{
    public function testMailPointsToTheAdministrationWithoutUsingTheStoredName(): void
    {
        $holder = User::factory()->unverified()->make(['name' => 'Erika']);

        $mail = (new EmailChangeTargetTakenNotification())->toMail($holder);

        $this->assertSame(__('app.email_change_target_taken_subject'), $mail->subject);
        $this->assertSame(__('app.mail_greeting_without_name'), $mail->greeting);
        $this->assertContains(__('app.email_change_target_taken_intro'), $mail->introLines);
        $this->assertContains(__('app.email_change_target_taken_hint'), $mail->introLines);
    }
}
