<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\VerifyEmailChangeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * Beide E-Mail-Wechsel-Notifications sind `ShouldQueueAfterCommit`; ihr
 * serialisierter Payload landet in `jobs` und bei Fehlschlag dauerhaft in
 * `failed_jobs`. Ohne `SerializesModels` serialisiert PHP das komplette
 * User-Objekt inklusive Passwort-Hash, `two_factor_secret`, Recovery-Codes und
 * `pending_email*`-Token-Hashes — `$hidden` greift bei `serialize()` nicht.
 * Erwartet wird nur der `ModelIdentifier` (ID), nie der Attribut-Snapshot.
 */
final class EmailChangeNotificationSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function testVerifyNotificationOmitsSensitiveUserAttributesFromQueuePayload(): void
    {
        $user = User::factory()->create();

        $this->assertPasswordHashNotSerialized(
            new VerifyEmailChangeNotification($user, 'plain-token', 'neu@example.test'),
            $user,
        );
    }

    public function testRequestedNotificationOmitsSensitiveUserAttributesFromQueuePayload(): void
    {
        $user = User::factory()->create();

        $this->assertPasswordHashNotSerialized(
            new EmailChangeRequestedNotification($user, 'plain-token', 'neu@example.test'),
            $user,
        );
    }

    private function assertPasswordHashNotSerialized(Notification $notification, User $user): void
    {
        $this->assertStringNotContainsString($user->password, serialize($notification));
    }
}
