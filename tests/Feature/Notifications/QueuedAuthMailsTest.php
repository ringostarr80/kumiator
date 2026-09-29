<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Reset-Mail geht nur für bekannte Adressen raus. Liefe sie synchron,
 * verriete die Antwortzeit, zu welcher Adresse ein Konto besteht.
 */
final class QueuedAuthMailsTest extends TestCase
{
    use RefreshDatabase;

    private const string FORGOT_PASSWORD_URL_PATH = '/forgot-password';

    public function testResetMailIsQueuedEncrypted(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => $user->email]);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification instanceof ResetPasswordNotification
                && $job->shouldBeEncrypted,
        );
    }

    /**
     * Der Worker kennt keine Session und fiele ohne mitgegebene Sprache auf
     * `APP_LOCALE` zurück.
     */
    public function testMailsKeepTheRequestLanguage(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $this->withSession(['locale' => 'de']);

        $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => $owner->email]);

        // Der Fake reicht die wirksame Sprache als viertes Argument; eine
        // zentral gesetzte trägt er nicht in die Notification ein.
        $sentInGerman = static fn (
            object $notification,
            array $channels,
            User $notifiable,
            ?string $locale,
        ): bool => $locale === 'de';

        Notification::assertSentTo($owner, ResetPasswordNotification::class, $sentInGerman);
    }
}
