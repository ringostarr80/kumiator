<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGIN_URL_PATH = '/login';
    private const string DEFAULT_IP = '127.0.0.1';
    private const int LOGIN_DECAY_SECONDS = 60;
    private const string TWO_FACTOR_CHALLENGE_URL_PATH = '/two-factor-challenge';

    public function testLoginScreenCanBeRendered(): void
    {
        $response = $this->get(self::LOGIN_URL_PATH);

        $response->assertStatus(200);
    }

    public function testUsersCanAuthenticateUsingTheLoginScreen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function testUsersCanNotAuthenticateWithInvalidPassword(): void
    {
        $user = User::factory()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function testUnknownEmailLoginRunsDummyHashAgainstTimingEnumeration(): void
    {
        // Die Antwortzeit selbst ist hier nicht messbar (BCRYPT_ROUNDS=4 in
        // der Test-Umgebung, ~1 ms gegen Request-Rauschen) — der Spy pinnt
        // stattdessen die Invariante, dass auch der Unbekannt-Pfad genau
        // einen KDF-Lauf ausführt. Bewusste Ausnahme von der
        // Mocks-vermeiden-Regel.
        $hash = Hash::spy();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => 'unbekannt@example.com',
            'password' => 'irrelevant-password',
        ]);

        $this->assertGuest();
        $hash->shouldHaveReceived('make')->once();
    }

    public function testUnapprovedUsersCanNotAuthenticate(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => __('app.login_pending_approval_message')]);

        $this->assertGuest();
    }

    public function testLoginIsThrottledAfterFiveAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failLogin('owner@example.com')->assertSessionHasErrors('email');
        }

        $this->failLogin('owner@example.com')->assertTooManyRequests();
    }

    /**
     * Mehrere Mitglieder hinter einer IP, etwa im WLAN des Vereinsheims, teilten
     * sich sonst die fünf Versuche.
     */
    public function testExhaustedAttemptsDoNotThrottleAnotherAccountOnTheSameIp(): void
    {
        $this->exhaustLoginAttempts('owner@example.com');

        $this->failLogin('member@example.com')->assertSessionHasErrors('email');
    }

    /**
     * Wer ein Konto von seinem Anschluss aus durchprobiert, sperrte sonst den
     * Inhaber an dessen Anschluss mit aus.
     */
    public function testExhaustedAttemptsDoNotThrottleTheSameAccountFromAnotherIp(): void
    {
        $this->exhaustLoginAttempts('owner@example.com', '203.0.113.7');

        $this->failLogin('owner@example.com', '198.51.100.1')->assertSessionHasErrors('email');
    }

    /**
     * Ohne Trenner ergäben beide Paare denselben Schlüssel
     * `owner@example.com192.0.2.15`: Ein frei gewählter Name sperrte dann ein
     * Konto an einem fremden Anschluss.
     */
    public function testANameThatAbsorbsPartOfTheIpDoesNotShareTheLimit(): void
    {
        $this->exhaustLoginAttempts('owner@example.com1', '92.0.2.15');

        $this->failLogin('owner@example.com', '192.0.2.15')->assertSessionHasErrors('email');
    }

    /**
     * Schon ein einzelner IPv6-Anschluss verfügt in der Regel über ein ganzes
     * /64-Netz. Zählte jede Adresse darin neu, riete er unbegrenzt weiter.
     */
    public function testExhaustedAttemptsThrottleTheWholeIpv6Network(): void
    {
        $this->exhaustLoginAttempts('owner@example.com', '2001:db8:1:2::1');

        $this->failLogin('owner@example.com', '2001:db8:1:2:ff00::2')->assertTooManyRequests();
    }

    /**
     * Ein gröberes Netz sperrte den Inhaber mit aus, sobald er darin sitzt.
     */
    public function testExhaustedAttemptsDoNotThrottleTheNeighbouringIpv6Network(): void
    {
        $this->exhaustLoginAttempts('owner@example.com', '2001:db8:1:2::1');

        $this->failLogin('owner@example.com', '2001:db8:1:3::1')->assertSessionHasErrors('email');
    }

    /**
     * Bei IPv4 ist die Adresse der Anschluss, die Nachbarn im selben Netz
     * gehören anderen.
     */
    public function testExhaustedAttemptsDoNotThrottleTheNeighbouringIpv4Address(): void
    {
        $this->exhaustLoginAttempts('owner@example.com', '203.0.113.7');

        $this->failLogin('owner@example.com', '203.0.113.8')->assertSessionHasErrors('email');
    }

    /**
     * Hinter einem Proxy, der per Unix-Socket weiterreicht, ist die Client-IP
     * keine IP.
     */
    public function testALoginWithoutAValidClientIpIsStillChecked(): void
    {
        $this->failLogin('owner@example.com', 'unix:')->assertSessionHasErrors('email');
    }

    /**
     * Ein gedrosselter Versuch kostet keinen Hash-Vergleich. Schriebe jeder einen
     * Eintrag, füllte ein Angreifer die Forensik-Tabelle im Takt seiner Requests.
     */
    public function testAThrottledLoginIsRecordedOncePerWindow(): void
    {
        $this->exhaustLoginAttempts('owner@example.com');
        $this->assertSame(1, $this->recordedLockouts());

        $this->failLogin('owner@example.com')->assertTooManyRequests();
        $this->assertSame(1, $this->recordedLockouts());
    }

    public function testTheNextWindowIsRecordedAgain(): void
    {
        $this->exhaustLoginAttempts('owner@example.com');
        $this->travel(self::LOGIN_DECAY_SECONDS)->seconds();

        $this->exhaustLoginAttempts('owner@example.com');

        $this->assertSame(2, $this->recordedLockouts());
    }

    /**
     * Die erste Sperre fällt in die letzte Sekunde des Fensters. Ohne
     * eingefrorene Zeit verstriche das Fenster schon vor ihr.
     */
    public function testALateLockoutDoesNotSwallowTheNextWindow(): void
    {
        $this->freezeTime();

        for ($i = 0; $i < 5; $i++) {
            $this->failLogin('owner@example.com');
        }

        $this->travel(self::LOGIN_DECAY_SECONDS - 1)->seconds();
        $this->failLogin('owner@example.com')->assertTooManyRequests();
        $this->travel(1)->seconds();

        $this->exhaustLoginAttempts('owner@example.com');

        $this->assertSame(2, $this->recordedLockouts());
    }

    /**
     * Sonst verschluckte die Sperre an einem Konto die an jedem anderen.
     */
    public function testEveryThrottledAccountIsRecorded(): void
    {
        $this->exhaustLoginAttempts('owner@example.com');
        $this->exhaustLoginAttempts('member@example.com');

        $this->assertSame(2, $this->recordedLockouts());
    }

    /**
     * Ein sechsstelliger Code wäre ohne Drossel in Reichweite eines
     * Rateangriffs.
     */
    public function testTwoFactorChallengeIsThrottledAfterFiveAttempts(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ])->saveOrFail();

        $this->withSession(['login.id' => $user->getKey()]);

        for ($i = 0; $i < 5; $i++) {
            $this->post(self::TWO_FACTOR_CHALLENGE_URL_PATH, ['code' => 'invalid'])->assertSessionHasErrors('code');
        }

        $this->post(self::TWO_FACTOR_CHALLENGE_URL_PATH, ['code' => 'invalid'])->assertTooManyRequests();
    }

    /**
     * Prüft die Sperre mit, sonst bewiese das Durchkommen danach nichts.
     */
    private function exhaustLoginAttempts(string $email, string $ip = self::DEFAULT_IP): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($email, $ip);
        }

        $this->failLogin($email, $ip)->assertTooManyRequests();
    }

    /**
     * @phpstan-impure
     */
    private function recordedLockouts(): int
    {
        return Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_locked_out')
            ->count();
    }

    /**
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function failLogin(string $email, string $ip = self::DEFAULT_IP): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(self::LOGIN_URL_PATH, [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
    }
}
