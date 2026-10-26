<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Den Audit-Trail lesen auch Personen und Backups, die nicht im Namen der App
 * signieren dürfen. Entstünde ein Audit-Hash mit dem Schlüssel der
 * URL-Signaturen, signierte das Log jede URL ohne Großbuchstaben, die jemand
 * als Login-E-Mail einschickt.
 */
final class AuditHashSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function testAFailedLoginDoesNotSignTheVerificationLink(): void
    {
        $user = User::factory()->unverified()->create();
        $url = route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->post('/login', ['email' => $url, 'password' => 'beliebig']);

        $signature = Activity::query()
            ->where('event', ActivityEvent::LOGIN_FAILED->value)
            ->sole()
            ->properties?->get('email_hash');
        $this->assertIsString($signature);

        $this->get($url . '?signature=' . $signature)->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }
}
