<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\Activity;
use App\Models\User;
use App\Services\Auth\Contracts\OtherSessionRevokerContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ConfirmsPassword;
use Tests\Support\InsertsSessions;
use Tests\TestCase;

final class BrowserSessionsTest extends TestCase
{
    use ConfirmsPassword;
    use InsertsSessions;
    use RefreshDatabase;

    public function testOtherBrowserSessionsCanBeLoggedOut(): void
    {
        $this->confirmPassword();
        $this->actingAs(User::factory()->create());

        Livewire::test(LogoutOtherBrowserSessionsForm::class)
            ->call('logoutOtherBrowserSessions')
            ->assertSuccessful();
    }

    /**
     * Ohne den Nachweis bliebe das Beenden fremder Sitzungen jedem offen, der an
     * eine Sitzung kommt — und ein abgeschalteter Passwort-Login wäre hier
     * wirkungslos, weil Jetstream den Hash selbst vergleicht.
     */
    public function testLoggingOutOtherSessionsRequiresAConfirmedSession(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(LogoutOtherBrowserSessionsForm::class)
            ->call('logoutOtherBrowserSessions')
            ->assertForbidden();
    }

    /**
     * Der Recaller-Cookie ist der Rückweg, der keinen Treiber braucht — liegen
     * die Sitzungen ausserhalb der Datenbank, ist seine Entwertung alles, was
     * vom Beenden übrig bleibt. Das Formular muss den Widerruf deshalb auch
     * dann erreichen und dem Nutzer den Abschluss zeigen.
     */
    public function testTheTokenRotatesEvenWithoutDatabaseSessions(): void
    {
        Config::set('session.driver', 'array');

        $this->confirmPassword();

        $user = User::factory()->create(['remember_token' => $stolen = Str::random(60)]);
        $this->actingAs($user);

        Livewire::test(LogoutOtherBrowserSessionsForm::class)
            ->set('confirmingLogout', true)
            ->call('logoutOtherBrowserSessions')
            ->assertSet('confirmingLogout', false)
            ->assertDispatched('loggedOut');

        $this->assertNotSame($stolen, $user->fresh()?->getRememberToken());
    }

    /**
     * Ruft die Methode an einer eigenen Instanz auf statt über `Livewire::test`:
     * Jetstreams `getSessionsProperty()` liest beim Rendern
     * `request()->session()->getId()`, und dem internen Request eines
     * Livewire-Tests weist die abgeschaltete Middleware keinen Session-Store zu.
     * Der geprüfte Pfad ist derselbe — Livewire ruft die Methode ebenso direkt auf.
     */
    public function testTheOtherSessionsEndAndTheLogoutIsAudited(): void
    {
        Config::set('session.driver', 'database');

        $this->confirmPassword();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->insertSession(Session::getId(), $user->id);
        $this->insertSession('other-device', $user->id);
        Activity::query()->delete();

        $component = new LogoutOtherBrowserSessionsForm();
        $component->boot(
            app(OtherSessionRevokerContract::class),
            app(UserSessionTerminatorContract::class),
        );
        $component->logoutOtherBrowserSessions(app(StatefulGuard::class));

        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', Session::getId())->count());

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'other_sessions_logged_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame(1, $activity->properties?->get('terminated_session_count'));
    }
}
