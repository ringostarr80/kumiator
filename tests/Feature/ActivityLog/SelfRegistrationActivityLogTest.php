<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Activity;
use App\Models\User;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Auth\SelfRegistrationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Validiert, dass die Web-Self-Registration im Activity-Log scharf vom
 * Admin-CLI-Pfad (`user:create`) abgegrenzt ist:
 *   - Web-Pfad (`POST /register`) → `user_self_registered` mit übersetzter Description
 *   - Admin-/CLI-Pfad (direktes `User::create()`) → unverändert `created`
 *
 * Mechanik: Ein Request-scoped Marker (`SelfRegistrationContext`) wird in
 * `CreateNewUser::create()` um den `User::create()`-Aufruf gesetzt; ein
 * `Activity::saving`-Listener im `AppServiceProvider` labelt den Eintrag
 * dann auf den fachlichen Code um. Diese Tests sichern beide Seiten der
 * Mechanik (Marker greift / Marker greift nicht / Marker wird auch bei
 * Exceptions sauber abgeräumt).
 */
final class SelfRegistrationActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRATION_EMAIL = 'erika@example.com';
    private const REGISTRATION_PASSWORD = 'Password!1234';

    /**
     * Web-Self-Registration: der `LogsActivity`-Trait erzeugt automatisch
     * einen `user.created`-Eintrag, der durch den `Activity::saving`-Hook
     * auf `user_self_registered` umgelabelt wird (inkl. übersetzter
     * Description). Causer/Subject sind identisch (User legt sich selbst an).
     */
    public function testSelfRegistrationProducesDedicatedActivityEntry(): void
    {
        Activity::query()->delete();

        $response = $this->post('/register', [
            'name' => 'Erika Mustermann',
            'email' => self::REGISTRATION_EMAIL,
            'password' => self::REGISTRATION_PASSWORD,
            'password_confirmation' => self::REGISTRATION_PASSWORD,
            'terms' => true,
        ]);

        $response->assertSessionHasNoErrors();

        $user = User::query()->where('email', self::REGISTRATION_EMAIL)->firstOrFail();

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('user_self_registered', $activity->event);
        $this->assertSame(__('app.activity_user_self_registered'), $activity->description);
    }

    /**
     * Negativer Gegenbeweis: Wenn der Marker NICHT aktiv ist (Admin-Pfad,
     * Seeder, Tests, `user:create`-Command), bleibt der Eintrag generisch.
     * Sonst wäre die Mechanik wertlos — sie soll fachliche von technischen
     * Anlagen unterscheiden, nicht alle User-Anlagen pauschal umlabeln.
     */
    public function testDirectUserCreationKeepsFactualUserCreatedEvent(): void
    {
        Activity::query()->delete();

        $user = User::create([
            'name' => 'Admin-Anlage',
            'email' => 'admin-anlage@example.com',
            'password' => 'irrelevant',
        ]);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        // Direkte User-Anlage außerhalb der Self-Reg-Strecke wird vom
        // channel-agnostischen User-Lifecycle-Hook auf den fachlichen
        // Code `user_created` aufgewertet — sie darf aber NICHT als
        // `user_self_registered` getarnt sein.
        $this->assertSame('user_created', $activity->event);
        $this->assertNotSame('user_self_registered', $activity->event);
    }

    /**
     * Der Self-Reg-Hook greift bewusst nur für den `user_created`-Übergang —
     * eine Namensänderung am selbst-registrierten User darf nicht plötzlich
     * als `user_self_registered` getarnt sein. Der channel-agnostische
     * User-Lifecycle-Hook labelt sie auf den fachlichen Code `user_renamed`.
     */
    public function testUpdatingSelfRegisteredUserBecomesUserRenamed(): void
    {
        $this->post('/register', [
            'name' => 'Erika Mustermann',
            'email' => self::REGISTRATION_EMAIL,
            'password' => self::REGISTRATION_PASSWORD,
            'password_confirmation' => self::REGISTRATION_PASSWORD,
            'terms' => true,
        ]);

        $user = User::query()->where('email', self::REGISTRATION_EMAIL)->firstOrFail();
        Activity::query()->delete();

        $user->updateOrFail(['name' => 'Erika Neu']);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('user_renamed', $activity->event);
        $this->assertNotSame('user_self_registered', $activity->event);
    }

    /**
     * Marker-Lifecycle: Auch wenn die innere DB-Transaktion exception't,
     * darf der Marker nicht im Folge-Request hängen bleiben. Sonst würde
     * eine spätere, völlig unabhängige User-Anlage (z. B. ein Worker, der
     * denselben PHP-Prozess wiederverwendet) fälschlich als Self-
     * Registration getarnt — das `try/finally` in `CreateNewUser` muss
     * greifen.
     *
     * Realer Fehler nach `markActive()`: Die `member`-Rolle (sonst im
     * `setUp()` angelegt) wird hier wieder entfernt, sodass `assignRole`
     * innerhalb der DB-Transaktion `RoleDoesNotExist` wirft — ein Fehler
     * NACH dem Marker-Setzen, exakt der Pfad, den `try/finally` absichert.
     */
    public function testMarkerIsClearedEvenIfRegistrationFails(): void
    {
        Role::query()->where('name', 'member')->where('guard_name', 'web')->delete();

        $createNewUser = $this->app->make(CreateNewUser::class);

        try {
            $createNewUser->create([
                'name' => 'Erika',
                'email' => self::REGISTRATION_EMAIL,
                'password' => self::REGISTRATION_PASSWORD,
                'password_confirmation' => self::REGISTRATION_PASSWORD,
            ]);
            $this->fail('Erwartete Exception bei fehlender member-Rolle blieb aus.');
        } catch (\Throwable) {
            // Exception ist erwartet — nur der Marker-Zustand ist relevant.
        }

        $this->assertFalse(
            SelfRegistrationContext::isActive(),
            'Marker hängt nach Fehlschlag fest — `try/finally` in CreateNewUser greift nicht.',
        );
    }

    /**
     * Das `user`-Log wird sonst nirgends auf andere Logs gemappt — wenn der
     * Marker aktiv ist, aber das Activity-Insert in einem anderen `log_name`
     * landet (z. B. `auth`), darf der Hook NICHT remappen. Schützt gegen
     * eine fälschliche Verallgemeinerung des Hooks.
     */
    public function testMarkerDoesNotRemapEntriesInOtherLogs(): void
    {
        Activity::query()->delete();

        $context = $this->app->make(SelfRegistrationContextContract::class);
        $context->markActive();

        try {
            // Künstliches `auth`-Log-Insert während der Marker aktiv ist.
            // Der Eintrag soll unverändert bleiben.
            ActivityFacade::useLog('auth')
                ->event('created')
                ->log('test');
        } finally {
            $context->clear();
        }

        $activity = Activity::query()->where('log_name', 'auth')->latest('id')->firstOrFail();
        $this->assertSame('created', $activity->event);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Marker zwischen Tests sauber halten — statische Felder überleben
        // sonst über die Test-Grenze hinweg.
        SelfRegistrationContext::clearStatically();

        // CreateNewUser weist die `member`-Rolle zu — die muss existieren,
        // sonst scheitert die Registration mit `RoleDoesNotExist`.
        Role::query()->firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
    }
}
