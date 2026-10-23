<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Auth;

use App\Models\User;
use App\Services\Auth\Contracts\PasswordAttemptLimiterContract;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class PasswordAttemptLimiterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jeder durchgelassene Versuch ist ein Rateversuch: Auch gleichzeitig
     * abgeschickte dürfen zusammen nicht über die Grenze kommen.
     */
    public function testConcurrentAttemptsStayWithinTheLimit(): void
    {
        // Der `array`-Store der Tests zählt nicht atomar hoch, der
        // `database`-Store tut es wie im Betrieb.
        RateLimiter::swap(new CacheRateLimiter(Cache::store('database')));
        $user = User::factory()->create();
        $limiter = app(PasswordAttemptLimiterContract::class);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->assertTrue($limiter->attempt($user));
        }

        $injected = false;
        $fifthPassed = false;

        // Echter fünfter Versuch, kein Mock: Er läuft vollständig durch, sobald
        // der sechste zum ersten Mal auf seinen Zähler zugreift.
        DB::listen(static function (QueryExecuted $query) use (&$injected, &$fifthPassed, $limiter, $user): void {
            $touchesCounter = array_any(
                $query->bindings,
                static fn (mixed $binding): bool => is_string($binding)
                    && str_contains($binding, 'password-attempt:'),
            );

            if ($injected || !$touchesCounter) {
                return;
            }

            $injected = true;
            $fifthPassed = $limiter->attempt($user);
        });

        $this->assertFalse($limiter->attempt($user));

        $this->assertTrue($injected);
        $this->assertTrue($fifthPassed);
    }

    /**
     * Ein kürzeres Fenster bremste das Raten kaum, ein längeres sperrte den
     * Eigentümer länger aus als nötig.
     */
    public function testTheWindowLastsOneMinute(): void
    {
        // Der Ablauf wird auf ganze Sekunden abgeschnitten: Ohne Einfrieren
        // läge die 59. Sekunde je nach Startzeitpunkt schon im nächsten Fenster.
        $this->freezeSecond();
        $user = User::factory()->create();
        $limiter = app(PasswordAttemptLimiterContract::class);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertTrue($limiter->attempt($user));
        }

        $this->travel(59)->seconds();
        $this->assertFalse($limiter->attempt($user));

        $this->travel(1)->seconds();
        $this->assertTrue($limiter->attempt($user));
    }
}
