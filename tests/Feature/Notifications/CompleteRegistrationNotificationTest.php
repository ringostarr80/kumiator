<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\CompleteRegistrationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class CompleteRegistrationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function testMailLeadsToSettingThePasswordWithoutUsingTheStoredName(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Erika']);

        $mail = (new CompleteRegistrationNotification())->toMail($user);
        $token = basename((string) parse_url($mail->actionUrl, PHP_URL_PATH));

        $this->assertSame(__('app.complete_registration_subject'), $mail->subject);
        $this->assertSame(__('app.mail_greeting_without_name'), $mail->greeting);
        $this->assertContains(__('app.complete_registration_intro'), $mail->introLines);
        $this->assertSame(route('password.reset', ['token' => $token, 'email' => $user->email]), $mail->actionUrl);
        $this->assertTrue(Password::tokenExists($user, $token));
    }
}
