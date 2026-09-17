<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserHasPasskeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wer auffrischt, misstraut der Instanz und will den Stand der Zeile. Eine
     * Antwort, die den Abgleich überlebt, machte daraus eine halbe — und
     * ausgerechnet der Griff dagegen träfe sie nicht.
     */
    public function testRefreshingTheUserAsksTheDatabaseAgainForPasskeys(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasPasskey());

        PasskeyCredential::factory()->for($user)->create();
        $user->refresh();

        $this->assertTrue($user->hasPasskey());
    }
}
