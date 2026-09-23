<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\PasskeyChange;
use App\Models\User;
use App\Notifications\PasskeyChangedNotification;
use Tests\TestCase;

final class PasskeyChangedNotificationTest extends TestCase
{
    public function testAddedMailNamesThePasskeyAndLeadsToTheProfile(): void
    {
        $user = User::factory()->make(['name' => 'Erika']);

        $mail = (new PasskeyChangedNotification(PasskeyChange::ADDED, 'Mein iPhone'))->toMail($user);

        $this->assertSame(__('app.passkey_added_subject'), $mail->subject);
        $this->assertSame(__('app.passkey_changed_greeting', ['name' => 'Erika']), $mail->greeting);
        $this->assertContains(__('app.passkey_added_intro', ['passkey' => 'Mein iPhone']), $mail->introLines);
        $this->assertSame(route('profile.show'), $mail->actionUrl);
    }

    public function testRemovedMailNamesThePasskey(): void
    {
        $user = User::factory()->make();

        $mail = (new PasskeyChangedNotification(PasskeyChange::REMOVED, 'Altes Handy'))->toMail($user);

        $this->assertSame(__('app.passkey_removed_subject'), $mail->subject);
        $this->assertContains(__('app.passkey_removed_intro', ['passkey' => 'Altes Handy']), $mail->introLines);
    }

    public function testPasskeyNameIsNotRenderedAsMarkdownLink(): void
    {
        $user = User::factory()->make();
        $name = '[Jetzt widerrufen](https://evil.example)';

        $html = (new PasskeyChangedNotification(PasskeyChange::ADDED, $name))->toMail($user)->render()->toHtml();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString($name, $html);
    }
}
