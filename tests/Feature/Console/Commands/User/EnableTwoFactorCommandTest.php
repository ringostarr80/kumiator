<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\Activity;
use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FixedSecretTwoFactorProvider;
use Tests\TestCase;

final class EnableTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';
    private const string TEST_SECRET = 'JBSWY3DPEHPK3PXP';

    /**
     * Ein Zeichen zeigt zwei übereinanderliegende Module, 1 steht für dunkel. Dunkle Module
     * bleiben frei, helle zeichnet der Block.
     */
    private const array MODULE_PAIRS = [
        ' ' => [1, 1],
        '▄' => [1, 0],
        '▀' => [0, 1],
        '█' => [0, 0],
    ];

    public function testTwoFactorCanBeEnabled(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->expectsOutputToContain(
                __('commands.enable_two_factor.success', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // Symmetrie zum UI-Pfad: sowohl `2fa_enabled` (durch
        // `EnableTwoFactorAuthentication`) als auch `2fa_confirmed` (durch
        // `ConfirmTwoFactorAuthentication`) müssen im auth-Log landen.
        $this->assertAuthEventLogged($user, '2fa_enabled');
        $this->assertAuthEventLogged($user, '2fa_confirmed');
    }

    public function testSecretAndQrCodeAreDisplayed(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.enable_two_factor.secret_label'))
            ->expectsOutputToContain(self::TEST_SECRET)
            ->expectsOutputToContain(__('commands.enable_two_factor.qr_code_label'))
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->assertSuccessful()
            ->run();
    }

    /**
     * Zu sehen bekommt der Admin die Codes nur hier. Fehlen sie in der Ausgabe, kann er sie
     * nicht weitergeben.
     */
    public function testRecoveryCodesAreDisplayed(): void
    {
        $this->bindFixedSecretProvider();

        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $lines = $this->runWithValidCode();

        /** @var list<string> $recoveryCodes */
        $recoveryCodes = $user->refresh()->recoveryCodes();
        $this->assertCount(8, $recoveryCodes);

        $label = array_search(__('commands.enable_two_factor.recovery_codes_label'), $lines, true);
        $this->assertIsInt($label);
        $this->assertSame(
            array_map(static fn (string $code): string => '  ' . $code, $recoveryCodes),
            array_slice($lines, $label + 1, count($recoveryCodes)),
        );
    }

    /**
     * Die Authenticator-App liest das Secret aus diesem Code. Finden kann ein Scanner ihn nur mit
     * hellem Rand.
     */
    public function testQrCodeShowsTheTotpUrl(): void
    {
        $this->bindFixedSecretProvider();

        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $lines = $this->runWithValidCode();

        $label = array_search(__('commands.enable_two_factor.qr_code_label'), $lines, true);
        $this->assertIsInt($label);
        $end = array_search('', array_slice($lines, $label + 1, preserve_keys: true), true);
        $this->assertIsInt($end);

        $matrix = Encoder::encode($user->refresh()->twoFactorQrCodeUrl(), ErrorCorrectionLevel::L())->getMatrix();
        $lightRow = array_fill(0, $matrix->getWidth() + 2, 0);
        $expected = [$lightRow];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [0];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y);
            }

            $expected[] = [...$row, 0];
        }

        // Unter dem Rand folgt eine weitere helle Modulzeile: Eine Textzeile trägt zwei, und die
        // Kante eines QR-Codes ist ungerade.
        $expected[] = $lightRow;
        $expected[] = $lightRow;

        $this->assertSame($expected, $this->modulesOf(array_slice($lines, $label + 1, $end - $label - 1)));
    }

    /**
     * Ohne Leerzeile läse sich die Überschrift des nächsten Blocks wie eine weitere Zeile des
     * vorigen.
     */
    public function testBlocksAreSeparatedByBlankLines(): void
    {
        $this->bindFixedSecretProvider();

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $lines = $this->runWithValidCode();

        foreach (['secret_label', 'recovery_codes_label'] as $key) {
            $label = array_search(__('commands.enable_two_factor.' . $key), $lines, true);
            $this->assertIsInt($label);
            $this->assertSame('', $lines[$label - 1] ?? null, $key);
        }
    }

    /**
     * Das Secret einer im Profil begonnenen, nie bestätigten Einrichtung hat der Browser schon
     * angezeigt. Gelten soll nur das Secret, das der Admin weitergibt.
     */
    public function testSetupStartedInTheProfileGetsANewSecret(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user);

        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutput(self::TEST_SECRET)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->assertSuccessful()
            ->run();
    }

    public function testInvalidCodeFailsAndCleansUp(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), '000000')
            ->expectsOutputToContain(__('commands.enable_two_factor.invalid_code'))
            ->assertFailed()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        // Cleanup-Pfad nutzt jetzt `DisableTwoFactorAuthentication` statt
        // direkten forceFill — der Listener erkennt am ungeänderten
        // `two_factor_confirmed_at` (war null, bleibt null) den Setup-Abbruch
        // und schreibt `2fa_setup_aborted`, NICHT `2fa_disabled`.
        $this->assertAuthEventLogged($user, '2fa_enabled');
        $this->assertAuthEventLogged($user, '2fa_setup_aborted');
        $this->assertAuthEventNotLogged($user, '2fa_disabled');
        $this->assertAuthEventNotLogged($user, '2fa_confirmed');
    }

    public function testAlreadyEnabledTwoFactorShowsWarning(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user, force: true);
        $user->forceFill(['two_factor_confirmed_at' => now()])->saveOrFail();

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(
                __('commands.enable_two_factor.already_enabled', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();
    }

    public function testEnableTwoFactorForNonExistentUserFails(): void
    {
        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }

    public function testTitleIsUnderlined(): void
    {
        // Unter `de`, weil deutsche Titel Umlaute tragen können: An ihnen zählte
        // `strlen` Bytes statt Zeichen, und die Linie geriete zu lang.
        $this->app->setLocale('de');
        $title = __('commands.enable_two_factor.title');

        $command = $this->artisan('user:enable-2fa');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutput($title)
            ->expectsOutput(str_repeat('-', mb_strlen($title)))
            // Die unbekannte E-Mail ist nur der kürzeste Weg bis zum Ende des Commands.
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->assertFailed()
            ->run();
    }

    private function bindFixedSecretProvider(): void
    {
        $this->app->instance(
            TwoFactorAuthenticationProvider::class,
            new FixedSecretTwoFactorProvider(new Google2FA(), self::TEST_SECRET),
        );
    }

    private function generateValidCode(string $secret): string
    {
        $google2fa = new Google2FA();

        return $google2fa->getCurrentOtp($secret);
    }

    /**
     * Über `CommandTester` statt `artisan()`: `expectsOutput()` prüft nur einzelne Zeilen, hier
     * liegt die ganze Ausgabe vor.
     *
     * @return list<string>
     */
    private function runWithValidCode(): array
    {
        /** @var Command $command */
        $command = $this->app->make(Kernel::class)->all()['user:enable-2fa'];

        $tester = new CommandTester($command);
        $tester->setInputs([self::TEST_EMAIL, $this->generateValidCode(self::TEST_SECRET)]);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        return explode(PHP_EOL, $tester->getDisplay());
    }

    /**
     * @param list<string> $lines
     * @return list<list<int>>
     */
    private function modulesOf(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $top = [];
            $bottom = [];

            foreach (mb_str_split($line) as $character) {
                [$top[], $bottom[]] = self::MODULE_PAIRS[$character]
                    ?? $this->fail(sprintf('Unerwartetes Zeichen "%s" im QR-Code.', $character));
            }

            $rows[] = $top;
            $rows[] = $bottom;
        }

        return $rows;
    }

    private function assertAuthEventLogged(User $user, string $eventCode): void
    {
        $exists = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $eventCode)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->exists();

        $this->assertTrue(
            $exists,
            sprintf("Auth-Log-Eintrag '%s' wurde erwartet, fehlt aber.", $eventCode),
        );
    }

    private function assertAuthEventNotLogged(User $user, string $eventCode): void
    {
        $exists = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $eventCode)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->exists();

        $this->assertFalse(
            $exists,
            sprintf("Auth-Log-Eintrag '%s' hätte NICHT geschrieben werden dürfen.", $eventCode),
        );
    }
}
