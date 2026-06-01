<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ActivityLogTable;
use App\Models\PasskeyCredential;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class ActivityLogAccessTest extends TestCase
{
    use RefreshDatabase;

    private const string ACTIVITY_LOG_URL = '/admin/activity-log';
    private const string ACTOR_NAME = 'Acting Admin';
    private const string SUBJECT_RENAMED = 'Subject Renamed';

    public function testGuestsAreRedirectedToLogin(): void
    {
        $response = $this->get(self::ACTIVITY_LOG_URL);

        $response->assertRedirect('/login');
    }

    public function testAuthenticatedUserWithoutPermissionIsForbidden(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(self::ACTIVITY_LOG_URL);

        $response->assertForbidden();
    }

    public function testAuthenticatedUserWithPermissionCanAccess(): void
    {
        $user = $this->makeAdmin();

        $response = $this->actingAs($user)->get(self::ACTIVITY_LOG_URL);

        $response->assertOk();
    }

    /**
     * Vor der Konsolidierung brach die Route-`can:`-
     * Middleware den Request vor dem Mount ab — der abgelehnte Navigations-
     * Zugriff blieb un-auditiert. Jetzt loggt `mount()` den Denial, und zwar
     * genau einmal (mount läuft pro Seiten-Render einmal → kein Doppel-Log).
     */
    public function testRouteLevelDenialIsLoggedExactlyOnce(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(self::ACTIVITY_LOG_URL)->assertForbidden();

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'security')
                ->where('event', 'authorization_denied')
                ->count(),
            'Der abgelehnte Route-Zugriff muss genau einen authorization_denied-Eintrag schreiben.',
        );

        $entry = Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'authorization_denied')
            ->firstOrFail();

        $this->assertSame($user->getKey(), $entry->causer_id);
        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame('activity-log.view', $properties['ability'] ?? null);
    }

    /**
     * Discoverability-Schutz: Der Activity-Log-Bereich ist nur sinnvoll
     * nutzbar, wenn Admins den Einsprung-Link in der Hauptnavigation sehen.
     * Fehlt der Link, wird das Feature still „versteckt" (URL nur per
     * Bookmark erreichbar).
     */
    public function testNavigationLinkIsVisibleForUsersWithPermission(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('admin.activity-log'));
    }

    /**
     * Negativ-Komplement: User ohne `activity-log.view`-Permission dürfen
     * den Link nicht sehen — sonst landeten sie beim Klick auf einer 403.
     */
    public function testNavigationLinkIsHiddenForUsersWithoutPermission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('admin.activity-log'));
    }

    /**
     * Defense-in-depth: Wird die Komponente direkt gemountet (z. B. weil sie
     * jemals außerhalb der `/admin/activity-log`-Route eingebettet wird), muss
     * der eigene `authorize()`-Aufruf in `mount()` greifen — auch dann, wenn
     * die umgebende Route die Permission nicht prüft.
     */
    public function testMountingComponentWithoutPermissionIsForbidden(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertForbidden();
    }

    public function testMountingComponentAsGuestIsForbidden(): void
    {
        Livewire::test(ActivityLogTable::class)
            ->assertForbidden();
    }

    public function testComponentRendersExistingActivityEntries(): void
    {
        $user = $this->makeAdmin();

        ActivityFacade::useLog('test')
            ->event('demo')
            ->log('Ein Testeintrag');

        $activity = Activity::query()
            ->where('log_name', 'test')
            ->latest('id')
            ->firstOrFail();

        Livewire::actingAs($user)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee($activity->description);
    }

    public function testRenderedTableShowsCauserAndSubjectNamesInsteadOfFqcn(): void
    {
        $actor = User::factory()->create(['name' => self::ACTOR_NAME]);
        $subject = User::factory()->create(['name' => 'Subject User']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => self::SUBJECT_RENAMED]);

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee(self::ACTOR_NAME)
            ->assertSee(self::SUBJECT_RENAMED);
    }

    /**
     * Regression-Schutz für die Refaktorierung weg von rohen FQCN im UI:
     * Im gerenderten Table dürfen weder `App\Models\User` noch
     * `App\Models\PasskeyCredential` (oder andere App-Models) als sichtbare
     * Zeichenkette auftauchen — stattdessen wird der echte `name` des Subjects/
     * Causers oder das übersetzte Fallback-Label gezeigt.
     */
    public function testRenderedTableContainsNoFqcn(): void
    {
        $actor = User::factory()->create(['name' => self::ACTOR_NAME]);
        $subject = User::factory()->create(['name' => 'Subject User']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => self::SUBJECT_RENAMED]);

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertDontSee('App\\Models\\User')
            ->assertDontSee('App\\Models\\PasskeyCredential');
    }

    /**
     * Wird der Subject-Datensatz nach Erstellung eines Activity-Eintrags
     * gelöscht (hier: soft-deleted, was die `morphTo`-Relation durch den
     * SoftDeletes-Scope auf `null` fallen lässt), zeigt das UI das übersetzte
     * Fallback-Label (`activity_log_deleted_record`) statt eines leeren oder
     * FQCN-haltigen Werts.
     */
    public function testRenderedTableShowsTranslatedFallbackForDeletedSubject(): void
    {
        $actor = User::factory()->create(['name' => self::ACTOR_NAME]);
        $subject = User::factory()->create(['name' => 'Soon Deleted']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => 'Final Name']);
        $subject->deleteOrFail();

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee(__('app.activity_log_deleted_record', [
                'type' => __('app.morph_user'),
            ]));
    }

    /**
     * Schützt die im PHPDoc von `ActivityLogTable::loadActivities()` festgehaltene
     * Performance-Charakteristik (1 Pagination + je 1 Query pro distinct Morph-Typ
     * für `subject` und `causer`, kein N+1).
     *
     * Setup: Eine volle Page mit gemischten Subject-Typen (User + Passkey) und
     * mindestens einem User-Causer. Erwarteter Query-Bedarf für das eigentliche
     * Laden: 1 Pagination-Count + 1 Pagination-Select + 1× User-Subjects +
     * 1× Passkey-Subjects + 1× User-Causer = 5. Plus Authentifizierungs-/
     * Permission-Lookups durch Livewire & Spatie.
     *
     * Die Schwelle (12) ist bewusst großzügig — kleinere Schwankungen durch
     * Framework-/Spatie-Updates sollen den Test nicht aufschrecken; ein echtes
     * N+1 (z. B. eine Query pro Row) würde die Schwelle dagegen weit reißen.
     */
    public function testRenderingPageStaysWithinQueryBudget(): void
    {
        $admin = $this->makeAdmin();
        $actor = User::factory()->create();

        // Eine Mischung aus User- und Passkey-Subjects sowie einem Causer-User —
        // damit die Page wirklich mehrere distinct Morph-Typen enthält.
        $this->actingAs($actor);

        for ($i = 0; $i < 5; $i++) {
            $subject = User::factory()->create();
            $subject->updateOrFail(['name' => 'Renamed ' . $i]);
        }

        for ($i = 0; $i < 5; $i++) {
            PasskeyCredential::factory()->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            12,
            $queryCount,
            "Die Activity-Log-Page sollte ≤ 12 Queries auslösen, hat aber {$queryCount} ausgelöst. "
            . 'Das deutet auf ein N+1 (z. B. fehlendes Eager-Loading) oder eine '
            . 'lazy geladene Relation im Blade-Template hin.',
        );
    }

    /**
     * Properties-Spalte: Eine Activity **mit** und eine **ohne** Properties
     * stehen in der Tabelle. Der Klick-Handler `showProperties(<id>)` als
     * eindeutiger HTML-Marker darf nur für die Activity mit Properties
     * gerendert werden — die Activity ohne Properties bekommt stattdessen
     * das Dash-Fallback (also keinen `showProperties`-Wire-Call).
     */
    public function testPropertiesColumnShowsIconOnlyForActivitiesWithProperties(): void
    {
        $admin = $this->makeAdmin();

        ActivityFacade::useLog('with-props')
            ->withProperties(['key' => 'value'])
            ->event('demo')
            ->log('Eintrag mit Properties');

        $withProps = Activity::query()
            ->where('log_name', 'with-props')
            ->latest('id')
            ->firstOrFail();

        ActivityFacade::useLog('without-props')
            ->event('demo')
            ->log('Eintrag ohne Properties');

        $withoutProps = Activity::query()
            ->where('log_name', 'without-props')
            ->latest('id')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee(__('app.activity_log_properties'))
            ->assertSeeHtml('showProperties(' . $withProps->id . ')')
            ->assertDontSeeHtml('showProperties(' . $withoutProps->id . ')');
    }

    /**
     * Aktion `showProperties`: Setzt den Modal-Sichtbarkeits-Flag und liefert
     * die Properties als Pretty-JSON, sodass das Modal-Template sie als
     * `<pre>`-Block darstellen kann. Anschließend setzt `closeProperties`
     * den State sauber zurück.
     */
    public function testShowPropertiesOpensModalAndClosePropertiesResetsIt(): void
    {
        $admin = $this->makeAdmin();

        ActivityFacade::useLog('with-props')
            ->withProperties(['attributes' => ['name' => 'Neuer Name']])
            ->event('updated')
            ->log('Eintrag aktualisiert');

        $activity = Activity::query()
            ->where('log_name', 'with-props')
            ->latest('id')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType

        $component->assertSet('showPropertiesModal', false);
        $component->assertSet('selectedProperties', null);

        $component->call('showProperties', $activity->id);
        $component->assertSet('showPropertiesModal', true);

        $selectedProperties = $component->get('selectedProperties');
        self::assertIsString($selectedProperties);
        self::assertStringContainsString('Neuer Name', $selectedProperties);
        self::assertStringContainsString(
            "\n",
            $selectedProperties,
            'JSON sollte Pretty-printed sein (Zeilenumbrüche enthalten).',
        );

        $component->call('closeProperties');
        $component->assertSet('showPropertiesModal', false);
        $component->assertSet('selectedProperties', null);
    }

    /**
     * Defense-in-Depth: `showProperties` für eine Activity **ohne** Properties
     * ist ein No-Op — Modal bleibt zu, kein verwirrender leerer Dialog. Schützt
     * den Pfad, falls jemand den Wire-Call manipuliert oder das Icon
     * versehentlich für leere Properties gerendert würde.
     */
    public function testShowPropertiesIsNoOpForActivityWithoutProperties(): void
    {
        $admin = $this->makeAdmin();

        ActivityFacade::useLog('empty')
            ->event('demo')
            ->log('Eintrag ohne Properties');

        $activity = Activity::query()
            ->where('log_name', 'empty')
            ->latest('id')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->call('showProperties', $activity->id);
        $component->assertSet('showPropertiesModal', false);
        $component->assertSet('selectedProperties', null);
    }

    /**
     * `showProperties` für eine **nicht-existierende** Activity ist ebenfalls
     * ein No-Op (kein 404, kein Fehler, Modal bleibt zu) — verhindert dass
     * eine manipulierte Wire-Call-ID die UI kaputt macht.
     */
    public function testShowPropertiesIsNoOpForUnknownActivityId(): void
    {
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->call('showProperties', 999_999);
        $component->assertSet('showPropertiesModal', false);
        $component->assertSet('selectedProperties', null);
    }

    /**
     * Erfolgreicher Lese-Zugriff aufs Activity-Log ist selbst dokumentations-
     * pflichtig (DSGVO Art. 5(2)/32): das Log bündelt personenbezogene Daten
     * aller Mitglieder. Der Eintrag landet im `security`-Channel mit
     * benanntem Causer (anders als `authorization_denied` ist der Akteur hier
     * zwingend zu identifizieren).
     */
    public function testSuccessfulAccessIsLogged(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk();

        $entry = $this->latestActivityLogViewed();

        $this->assertNotNull($entry, 'Erlaubter Zugriff muss einen activity_log_viewed-Eintrag schreiben.');
        $this->assertSame($admin->getKey(), $entry->causer_id);
        $this->assertSame($admin->getMorphClass(), $entry->causer_type);
        $this->assertSame(__('app.activity_activity_log_viewed'), $entry->description);
    }

    /**
     * Sichert die „1 Eintrag pro Mount"-Entscheidung ab: `mount()` läuft pro
     * Livewire-Lebenszyklus genau einmal — Pagination/Re-Render dürfen keinen
     * zusätzlichen Zugriffs-Eintrag erzeugen, sonst flutete jedes Blättern das Log.
     */
    public function testPaginationDoesNotMultiplyAccessLog(): void
    {
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertOk();
        $component->call('gotoPage', 2);
        $component->call('gotoPage', 1);

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'security')
                ->where('event', 'activity_log_viewed')
                ->count(),
            'Pagination/Re-Render darf keinen zusätzlichen Eintrag erzeugen — mount() läuft nur einmal.',
        );
    }

    /**
     * Speist Rollen + Permissions aus dem produktiven `RoleSeeder` statt sie
     * inline anzulegen — wenn der Seeder später um eine Permission ergänzt
     * wird, sehen die Tests sie automatisch. Sonst entstünde Drift zwischen
     * Test-Setup und Production-State.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function latestActivityLogViewed(): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'activity_log_viewed')
            ->latest('id')
            ->first();
    }
}
