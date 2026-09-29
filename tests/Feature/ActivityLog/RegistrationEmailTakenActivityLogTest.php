<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

/**
 * Der Inhaber einer vergebenen Adresse bekommt eine Mail, die er nicht
 * angefordert hat. Ohne Eintrag fände die Administration nicht, woher sie kam.
 */
final class RegistrationEmailTakenActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_URL_PATH = '/register';
    private const string CLIENT_IP = '203.0.113.7';
    private const string USER_AGENT = 'Mozilla/5.0 (TestAgent)';

    public function testNoticeToTheOwnerIsLogged(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT_IP])
            ->withHeader('User-Agent', self::USER_AGENT)
            ->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'registration_email_taken')
            ->sole();

        $this->assertSame(__('app.activity_registration_email_taken'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertSame($owner->getMorphClass(), $activity->subject_type);
        $this->assertSame($owner->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('203.0.113.0/24', $properties['ip'] ?? null);
        $this->assertSame(self::USER_AGENT, $properties['user_agent'] ?? null);
    }

    public function testLinkToSetThePasswordIsLogged(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $this->assertSame(1, $this->loggedAttemptsFor($owner));
    }

    public function testThrottledAttemptLeavesNoEntry(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $this->assertSame(1, $this->loggedAttemptsFor($owner));
    }

    public function testDepartedAccountLeavesNoEntry(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $owner->deleteOrFail();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $this->assertSame(0, $this->loggedAttemptsFor($owner));
    }

    private function loggedAttemptsFor(User $owner): int
    {
        return Activity::query()
            ->where('event', 'registration_email_taken')
            ->where('subject_id', $owner->getKey())
            ->count();
    }

    /**
     * @return array<string, string|bool>
     */
    private function registrationInput(string $email): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ];
    }
}
