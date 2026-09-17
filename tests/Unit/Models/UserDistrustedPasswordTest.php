<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class UserDistrustedPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Stempel entsteht am Model, damit keine schreibende Stelle ihn vergessen
     * kann. Andere Änderungen dürfen ihn nicht bewegen — sonst machte ein
     * Namenswechsel aus dem verdächtigen Passwort ein vertrauenswürdiges.
     */
    public function testOnlyAPasswordChangeMovesTheTimestamp(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->name = 'Anderer Name';
        $user->saveOrFail();

        $this->assertSame('2026-01-01 10:00:00', $user->password_changed_at?->toDateTimeString());

        $user->password = 'neues-passwort';
        $user->saveOrFail();

        $this->assertSame('2026-01-02 10:00:00', $user->password_changed_at->toDateTimeString());
    }

    public function testAnOpenPasswordLoginDistrustsNothing(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasDistrustedPassword());
    }

    public function testThePasswordFromBeforeTheDisablingIsDistrusted(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();

        $this->assertTrue($user->hasDistrustedPassword());
    }

    public function testAPasswordSetAfterTheDisablingIsTrusted(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password = 'neues-passwort';
        $user->saveOrFail();

        $this->assertFalse($user->hasDistrustedPassword());
    }

    /**
     * Bestandszeilen von vor der Spalte und Pfade ohne Model-Events tragen keinen
     * Stempel. Verwerfen ist dort die sichere Richtung.
     */
    public function testWithoutATimestampThePasswordCountsAsOld(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $user->password_changed_at = null;
        $user->saveOrFail();

        $this->assertTrue($user->hasDistrustedPassword());
    }
}
