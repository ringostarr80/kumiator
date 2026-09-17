<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';

    public function testPasswordCanBeReset(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
            'password' => Hash::make('old-password'),
        ]);

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->expectsOutputToContain(__('commands.reset_password.success', [
                'name' => self::TEST_NAME,
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('new-password123', $user->password));
    }

    public function testResetPasswordFailsForNonExistentUser(): void
    {
        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }

    public function testResetPasswordFailsWhenPasswordsDoNotMatch(): void
    {
        User::factory()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'different-password')
            ->assertFailed()
            ->run();
    }

    public function testResetPasswordFailsWhenPasswordIsTooShort(): void
    {
        User::factory()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'short')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'short')
            ->assertFailed()
            ->run();
    }

    /**
     * Der einzige Weg zurück, wenn jemand sein Passwort vergisst, nachdem er die
     * Passwort-Anmeldung abgeschaltet hat: Der Reset-Link erreicht solche Konten
     * nicht mehr.
     */
    public function testResetPasswordTurnsPasswordLoginBackOn(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
            'password_login_disabled_at' => now(),
        ]);

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->expectsConfirmation(__('commands.reset_password.confirm_reenable_password_login'), 'yes')
            ->expectsOutputToContain(__('commands.reset_password.password_login_reenabled'))
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->firstOrFail();
        $this->assertNull($user->password_login_disabled_at);
    }

    /**
     * Ein Konto, das bewusst nur noch per Passkey hineinlässt, soll ein neues
     * Passwort bekommen können, ohne dass dabei still eine Sicherheitseinstellung
     * fällt.
     */
    public function testResetPasswordCanLeavePasswordLoginTurnedOff(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
            'password_login_disabled_at' => now(),
        ]);
        Activity::query()->delete();

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->expectsConfirmation(__('commands.reset_password.confirm_reenable_password_login'), 'no')
            ->expectsOutputToContain(__('commands.reset_password.password_login_kept_disabled'))
            ->assertSuccessful()
            ->run();

        $user->refresh();

        $this->assertTrue(Hash::check('new-password123', $user->password));
        $this->assertNotNull($user->password_login_disabled_at);
        $this->assertSame(
            0,
            Activity::query()->where('event', ActivityEvent::PASSWORD_LOGIN_ENABLED->value)->count(),
        );
    }

    /**
     * Was ein blindes Enter auslöst, entscheidet allein der Default der Frage.
     * `expectsConfirmation` setzt die Antwort und käme daran vorbei, deshalb
     * läuft dieser Fall über einen echten Eingabestrom.
     */
    public function testPressingEnterLeavesPasswordLoginTurnedOff(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
            'password_login_disabled_at' => now(),
        ]);

        /** @var Command $command */
        $command = $this->app->make(Kernel::class)->all()['user:reset-password'];

        $tester = new CommandTester($command);
        $tester->setInputs([self::TEST_EMAIL, 'new-password123', 'new-password123', '']);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $user->refresh();

        $this->assertTrue(Hash::check('new-password123', $user->password));
        $this->assertNotNull($user->password_login_disabled_at);
    }

    public function testResetPasswordStaysQuietWhenPasswordLoginWasNeverOff(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->doesntExpectOutputToContain(__('commands.reset_password.password_login_reenabled'))
            ->assertSuccessful()
            ->run();
    }

    /**
     * Abgeschaltet wird der Passwort-Login bei Verdacht. Wer ihn schließt, während
     * der Admin gerade die Passwörter tippt, darf nicht ungefragt wieder mit dem
     * Passwort hineingelassen werden, das der Admin danach durchgibt — die Frage
     * muss den Stand von jetzt sehen, nicht den vom Beginn des Dialogs.
     */
    public function testTheQuestionFollowsTheCurrentSwitchNotTheLoadedInstance(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);
        PasskeyCredential::factory()->for($user)->create();

        // Schließt den Schalter, sobald das Kommando seine Instanz geladen hat —
        // der Umschalter lädt die Zeile selbst noch einmal, daher nur beim ersten Mal.
        $closed = false;
        User::retrieved(function () use (&$closed, $user): void {
            if ($closed) {
                return;
            }

            $closed = true;
            app(LoginMethodChangerContract::class)->disablePasswordLogin($user);
        });

        $command = $this->artisan('user:reset-password');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->expectsConfirmation(__('commands.reset_password.confirm_reenable_password_login'), 'no')
            ->expectsOutputToContain(__('commands.reset_password.password_login_kept_disabled'))
            ->assertSuccessful()
            ->run();

        $this->assertTrue($user->fresh()?->isPasswordLoginDisabled());
    }
}
