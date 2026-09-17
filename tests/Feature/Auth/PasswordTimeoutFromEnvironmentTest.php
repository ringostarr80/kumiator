<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Validator;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

/**
 * `Env::get()` reicht Zahlen als String durch — nur `true`/`false`/`empty`/`null`
 * werden umgewandelt. Die `recently_confirmed`-Regel liest die Frist streng über
 * `Config::integer()`, und die wirft bei allem, was kein `int` ist. Ohne den Cast
 * in `config/auth.php` genügte ein Eintrag in der `.env`, damit jedes Speichern
 * an einem Konto ohne Passwort-Login im 500er endet.
 */
final class PasswordTimeoutFromEnvironmentTest extends TestCase
{
    use ConfirmsPassword;

    private const int WINDOW_SECONDS = 3_600;

    public function testAConfirmationInsideTheShortenedWindowStillCounts(): void
    {
        $this->confirmPassword(100);

        $this->assertTrue($this->recentlyConfirmedPasses());
    }

    /**
     * Das Alter liegt zwischen der Frist aus der Umgebung und den drei Stunden
     * aus dem Default: Nur so belegt der Fehlschlag, dass tatsächlich der Wert
     * aus der Umgebung gilt und nicht der einprogrammierte.
     */
    public function testAConfirmationBeyondTheShortenedWindowNoLongerCounts(): void
    {
        $this->confirmPassword(self::WINDOW_SECONDS + 400);

        $this->assertFalse($this->recentlyConfirmedPasses());
    }

    /**
     * Die Variable steht vor `parent::setUp()`, weil die Anwendung ihre Config
     * beim Booten einmal liest — danach gesetzt bliebe sie wirkungslos.
     */
    protected function setUp(): void
    {
        putenv('AUTH_PASSWORD_TIMEOUT=' . self::WINDOW_SECONDS);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('AUTH_PASSWORD_TIMEOUT');

        parent::tearDown();
    }

    /**
     * Leere Eingabedaten, weil die Regel per `extendImplicit` registriert ist:
     * Sie greift gerade dann, wenn das Feld fehlt, und liest ohnehin nur die
     * Session.
     */
    private function recentlyConfirmedPasses(): bool
    {
        return Validator::make([], ['current_password' => ['recently_confirmed']])->passes();
    }
}
