<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const string FORGOT_PASSWORD_URL_PATH = '/forgot-password';
    private const string RESET_PASSWORD_URL_PATH = '/reset-password';
    private const string UNKNOWN_EMAIL = 'unknown@example.com';

    public function testResetPasswordLinkScreenCanBeRendered(): void
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->markTestSkipped('Password updates are not enabled.');
        }

        $response = $this->get(self::FORGOT_PASSWORD_URL_PATH);

        $response->assertStatus(200);
    }

    public function testResetPasswordLinkCanBeRequested(): void
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->markTestSkipped('Password updates are not enabled.');
        }

        Notification::fake();

        $user = User::factory()->create();

        $this->post(self::FORGOT_PASSWORD_URL_PATH, [
            'email' => $user->email,
        ]);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function testResetPasswordScreenCanBeRendered(): void
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->markTestSkipped('Password updates are not enabled.');
        }

        Notification::fake();

        $user = User::factory()->create();

        $this->post(self::FORGOT_PASSWORD_URL_PATH, [
            'email' => $user->email,
        ]);

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) {
                $response = $this->get('/reset-password/' . $notification->token);

                $response->assertStatus(200);

                return true;
            },
        );
    }

    public function testPasswordCanBeResetWithValidToken(): void
    {
        if (! Features::enabled(Features::resetPasswords())) {
            $this->markTestSkipped('Password updates are not enabled.');
        }

        Notification::fake();

        $user = User::factory()->create();

        $this->post(self::FORGOT_PASSWORD_URL_PATH, [
            'email' => $user->email,
        ]);

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($user) {
                $response = $this->post('/reset-password', [
                    'token' => $notification->token,
                    'email' => $user->email,
                    'password' => 'password',
                    'password_confirmation' => 'password',
                ]);

                $response->assertSessionHasNoErrors();

                return true;
            },
        );
    }

    /**
     * Wiche die Antwort ab, verriete sie einem Unbeteiligten, ob zur Adresse ein
     * Konto besteht.
     */
    public function testUnknownAddressAnswersLikeAKnownOne(): void
    {
        Notification::fake();

        $response = $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => self::UNKNOWN_EMAIL]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', __('passwords.sent'));
        Notification::assertNothingSent();
    }

    /**
     * Die Sperrfrist des Brokers gilt nur für bekannte Adressen; eine eigene
     * Meldung für sie verriete dasselbe.
     */
    public function testRepeatedRequestAnswersLikeTheFirst(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => $user->email]);
        $response = $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => $user->email]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', __('passwords.sent'));
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function testResetWithUnknownAddressAnswersLikeAnInvalidToken(): void
    {
        $user = User::factory()->create();

        $unknownResponse = $this->post(self::RESET_PASSWORD_URL_PATH, $this->resetInput(self::UNKNOWN_EMAIL));
        $knownResponse = $this->post(self::RESET_PASSWORD_URL_PATH, $this->resetInput($user->email));

        $unknownResponse->assertSessionHasErrors(['email' => __('passwords.token')]);
        $knownResponse->assertSessionHasErrors(['email' => __('passwords.token')]);
    }

    public function testResetLinkRequestIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => self::UNKNOWN_EMAIL])->assertSessionHasNoErrors();
        }

        $this->post(self::FORGOT_PASSWORD_URL_PATH, ['email' => self::UNKNOWN_EMAIL])->assertTooManyRequests();
    }

    public function testResetIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(self::RESET_PASSWORD_URL_PATH, $this->resetInput(self::UNKNOWN_EMAIL))
                ->assertSessionHasErrors(['email' => __('passwords.token')]);
        }

        $this->post(self::RESET_PASSWORD_URL_PATH, $this->resetInput(self::UNKNOWN_EMAIL))->assertTooManyRequests();
    }

    /**
     * @return array<string, string>
     */
    private function resetInput(string $email): array
    {
        return [
            'token' => 'invented-token',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }
}
