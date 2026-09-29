<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Auth;

use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use App\Services\Auth\Contracts\ExistingAccountNotifierContract;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class ExistingAccountNotifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Registrierung ist nur pro IP gedrosselt: Gleichzeitige Versuche von
     * verschiedenen IPs dürfen dem Inhaber trotzdem nur eine Mail schicken.
     */
    public function testConcurrentAttemptsTellTheOwnerOnlyOnce(): void
    {
        Notification::fake();
        // Der `array`-Store der Tests zählt nicht atomar hoch, der
        // `database`-Store tut es wie im Betrieb.
        RateLimiter::swap(new CacheRateLimiter(Cache::store('database')));
        $owner = User::factory()->create();
        $notifier = app(ExistingAccountNotifierContract::class);
        $injected = false;

        // Echter zweiter Versuch, kein Mock: Er läuft vollständig durch, sobald
        // der erste zum ersten Mal auf seinen Drossel-Zähler zugreift.
        DB::listen(static function (QueryExecuted $query) use (&$injected, $notifier, $owner): void {
            $touchesThrottle = array_any(
                $query->bindings,
                static fn (mixed $binding): bool => is_string($binding)
                    && str_contains($binding, 'existing-account-notice:'),
            );

            if ($injected || !$touchesThrottle) {
                return;
            }

            $injected = true;
            $notifier->notify($owner);
        });

        $notifier->notify($owner);

        $this->assertTrue($injected);
        Notification::assertSentToTimes($owner, AccountAlreadyExistsNotification::class, 1);
    }
}
