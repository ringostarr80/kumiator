<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use App\Notifications\CompleteRegistrationNotification;
use App\Notifications\EmailChangeTargetTakenNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Jetstream\Jetstream;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reset-, Bestätigungs- und Hinweis-Mail gehen nur für bestimmte Adressen
 * raus. Liefen sie synchron, verriete die Antwortzeit, zu welcher Adresse ein
 * Konto besteht.
 */
final class QueuedAuthMailsTest extends TestCase
{
    use RefreshDatabase;

    private const string FORGOT_PASSWORD_URL_PATH = '/forgot-password';
    private const string REGISTER_URL_PATH = '/register';
    private const string NEW_EMAIL = 'neu@example.com';

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

    public function testVerificationMailIsQueued(): void
    {
        Queue::fake();
        Role::findOrCreate('member');

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::NEW_EMAIL));

        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification instanceof VerifyEmailNotification,
        );
    }

    public function testAccountExistsMailIsQueued(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification
                instanceof AccountAlreadyExistsNotification,
        );
    }

    public function testCompleteRegistrationMailIsQueued(): void
    {
        Queue::fake();
        $owner = User::factory()->unverified()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification
                instanceof CompleteRegistrationNotification,
        );
    }

    public function testEmailChangeTargetTakenMailIsQueued(): void
    {
        Queue::fake();
        $holder = User::factory()->create();
        $this->actingAs($user = User::factory()->create());

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => $holder->email,
            'current_password' => 'password',
        ]);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification
                instanceof EmailChangeTargetTakenNotification,
        );
    }

    /**
     * Der Worker kennt keine Session und fiele ohne mitgegebene Sprache auf
     * `APP_LOCALE` zurück.
     */
    public function testMailsKeepTheRequestLanguage(): void
    {
        Notification::fake();
        Role::findOrCreate('member');
        $owner = User::factory()->create();
        $unverifiedOwner = User::factory()->unverified()->create();
        $this->withSession(['locale' => 'de']);

        $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => $owner->email]);
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::NEW_EMAIL));
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($unverifiedOwner->email));

        $newUser = User::query()->where('email', self::NEW_EMAIL)->firstOrFail();

        // Der Fake reicht die wirksame Sprache als viertes Argument; eine
        // zentral gesetzte trägt er nicht in die Notification ein.
        $sentInGerman = static fn (
            object $notification,
            array $channels,
            User $notifiable,
            ?string $locale,
        ): bool => $locale === 'de';

        Notification::assertSentTo($owner, ResetPasswordNotification::class, $sentInGerman);
        Notification::assertSentTo($newUser, VerifyEmailNotification::class, $sentInGerman);
        Notification::assertSentTo($owner, AccountAlreadyExistsNotification::class, $sentInGerman);
        Notification::assertSentTo($unverifiedOwner, CompleteRegistrationNotification::class, $sentInGerman);
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
