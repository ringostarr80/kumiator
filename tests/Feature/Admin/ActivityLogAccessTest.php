<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ActivityEvent;
use App\Livewire\Admin\ActivityLogTable;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Tests\TestCase;

final class ActivityLogAccessTest extends TestCase
{
    use RefreshDatabase;

    private const string ACTIVITY_LOG_URL = '/admin/activity-log';
    private const string ACTOR_NAME = 'Acting Admin';
    private const string SUBJECT_RENAMED = 'Subject Renamed';
    private const string RENAMED_PREFIX = 'Renamed ';

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
        $user = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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

    /**
     * Folge-Requests laufen ohne erneuten `mount()` — ohne Re-Check in
     * `hydrate()` überlebte eine bereits geöffnete Admin-Seite den
     * Permission-Entzug bis zum Page-Reload. Der Wire-Call muss abgelehnt
     * werden, bevor die Aktion Properties in den State lädt, und der Versuch
     * muss wie der abgelehnte Erst-Zugriff auditiert werden.
     */
    public function testActionAfterPermissionRevocationIsForbiddenAndAudited(): void
    {
        $admin = $this->makeAuditor();

        ActivityFacade::useLog('with-props')
            ->withProperties(['key' => 'value'])
            ->event('demo')
            ->log('Eintrag mit Properties');

        $activity = Activity::query()
            ->where('log_name', 'with-props')
            ->latest('id')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertOk();

        $admin->revokePermissionTo('activity-log.view');

        $component->call('showProperties', $activity->id);
        $component->assertForbidden();
        $component->assertSet('showPropertiesModal', false);
        $component->assertSet('selectedProperties', null);

        $entry = Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'authorization_denied')
            ->firstOrFail();

        $this->assertSame($admin->getKey(), $entry->causer_id);
        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame('activity-log.view', $properties['ability'] ?? null);
    }

    /**
     * Der Entzug muss auch die `render()`-Pfade schließen: Filter-Updates
     * (stellvertretend für Sortierung/Pagination) laden bei jedem Wire-Request
     * frische Zeilen — nicht nur das Properties-Modal.
     */
    public function testFilterUpdateAfterPermissionRevocationIsForbidden(): void
    {
        $admin = $this->makeAuditor();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertOk();

        $admin->revokePermissionTo('activity-log.view');

        $component->set('filterChannel', 'auth');
        $component->assertForbidden();
    }

    public function testComponentRendersExistingActivityEntries(): void
    {
        $user = $this->makeAuditor();

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

    /**
     * Die Beschreibung wird read-time aus dem `event`-Code aufgelöst: bei einem
     * bekannten Code zeigt das UI die (aktuelle, lokalisierte) Enum-Beschreibung.
     * Der an `log()` übergebene String wird nicht persistiert und nie gezeigt.
     */
    public function testRenderedDescriptionIsResolvedFromEventCode(): void
    {
        $admin = $this->makeAuditor();

        ActivityFacade::useLog('auth')
            ->event(ActivityEvent::LOGIN_FAILED->value)
            ->log('VERALTETE EINGEFRORENE BESCHREIBUNG');

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee(ActivityEvent::LOGIN_FAILED->description())
            ->assertDontSee('VERALTETE EINGEFRORENE BESCHREIBUNG');
    }

    /**
     * Gegenstück: für einen zurückgezogenen Event-Code ohne Enum-Case fällt die
     * Anzeige auf den rohen `event`-Code zurück — die `description`-Spalte gibt es
     * nicht mehr. Der `log()`-String wird nicht persistiert und nie gezeigt.
     */
    public function testRenderedDescriptionFallsBackToRawEventCodeForUnknownEvent(): void
    {
        $admin = $this->makeAuditor();

        ActivityFacade::useLog('auth')
            ->event('retired_event_code')
            ->log('Nicht gespeicherter Text');

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee('retired_event_code')
            ->assertDontSee('Nicht gespeicherter Text');
    }

    public function testRenderedTableShowsCauserAndSubjectNamesInsteadOfFqcn(): void
    {
        $actor = User::factory()->create(['name' => self::ACTOR_NAME]);
        $subject = User::factory()->create(['name' => 'Subject User']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => self::SUBJECT_RENAMED]);

        $admin = $this->makeAuditor();

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

        $admin = $this->makeAuditor();

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

        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();
        $actor = User::factory()->create();

        // Eine Mischung aus User- und Passkey-Subjects sowie einem Causer-User —
        // damit die Page wirklich mehrere distinct Morph-Typen enthält.
        $this->actingAs($actor);

        for ($i = 0; $i < 5; $i++) {
            $subject = User::factory()->create();
            $subject->updateOrFail(['name' => self::RENAMED_PREFIX . $i]);
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
        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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
        $admin = $this->makeAuditor();

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
     * Das Pagination-Control steht jetzt sowohl oberhalb als auch unterhalb der
     * Tabelle. Jede Instanz bringt Livewires eingebaute Treffer-Info
     * ("Showing X to Y of Z results") mit. Setup mit > PER_PAGE (25) Einträgen,
     * damit das Control überhaupt rendert — Livewire blendet es bei nur einer
     * Seite komplett aus.
     */
    public function testPaginationControlAppearsAboveAndBelowTheTable(): void
    {
        $admin = $this->makeAuditor();
        $actor = User::factory()->create();
        $this->actingAs($actor);

        for ($i = 0; $i < 30; $i++) {
            $subject = User::factory()->create();
            $subject->updateOrFail(['name' => self::RENAMED_PREFIX . $i]);
        }

        $component = Livewire::actingAs($admin)
            ->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertOk();

        $html = $component->html();

        $this->assertSame(
            2,
            substr_count($html, 'aria-label="Pagination Navigation"'),
            'Das Pagination-Control muss oberhalb UND unterhalb der Tabelle stehen.',
        );
        $this->assertSame(
            2,
            substr_count($html, 'Showing'),
            'Die eingebaute Treffer-Info muss bei beiden Pagination-Instanzen erscheinen.',
        );
    }

    /**
     * Die Zeitpunkt-Spalte ist sortierbar: `sortByCreatedAt` toggelt zwischen
     * `desc` (Default, neueste zuerst) und `asc` und dreht damit die Reihenfolge
     * der Rows im DOM. Der Richtungswechsel springt zudem zurück auf Seite 1.
     *
     * Die beiden Marker werden über `travelTo()` auf distinkte Zeitpunkte gesetzt,
     * damit die erwartete Reihenfolge nicht von Sekunden-genauer Insert-Reihenfolge
     * abhängt.
     */
    public function testCreatedAtColumnIsSortableAndTogglesDirection(): void
    {
        $admin = $this->makeAuditor();

        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'), function (): void {
            ActivityFacade::useLog('sort')->event('sort_alt')->log('');
        });
        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'), function (): void {
            ActivityFacade::useLog('sort')->event('sort_neu')->log('');
        });

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertOk();
        $component->assertSee('sort_alt');
        $component->assertSee('sort_neu');

        // Default desc: neuester Eintrag steht im DOM vor dem ältesten.
        $component->assertSet('sortDirection', 'desc');
        $descHtml = $component->html();
        $this->assertLessThan(
            strpos($descHtml, 'sort_alt'),
            strpos($descHtml, 'sort_neu'),
            'Bei desc muss der neueste Eintrag oberhalb des ältesten stehen.',
        );

        // Toggle → asc: Reihenfolge dreht sich um.
        $component->call('sortByCreatedAt');
        $component->assertSet('sortDirection', 'asc');
        $ascHtml = $component->html();
        $this->assertLessThan(
            strpos($ascHtml, 'sort_neu'),
            strpos($ascHtml, 'sort_alt'),
            'Bei asc muss der älteste Eintrag oberhalb des neuesten stehen.',
        );

        // Richtungswechsel springt zurück auf Seite 1.
        $component->call('gotoPage', 2);
        $component->assertSet('paginators.page', 2);
        $component->call('sortByCreatedAt');
        $component->assertSet('paginators.page', 1);
    }

    public function testFilterByChannelLimitsResults(): void
    {
        $admin = $this->makeAuditor();

        ActivityFacade::useLog('auth')->event('marker_auth')->log('');
        ActivityFacade::useLog('user')->event('marker_user')->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterChannel', 'auth');

        $component->assertSee('marker_auth');
        $component->assertDontSee('marker_user');
    }

    public function testFilterByEventLimitsResults(): void
    {
        $admin = $this->makeAuditor();

        // Bewusst Nicht-Enum-Codes: die Beschreibung wird read-time aus dem
        // event-Code abgeleitet und fällt für unbekannte Codes auf den rohen
        // Code zurück — so bleibt der Marker eindeutig der Zeile zuordenbar
        // (Enum-Codes zeigten die Beschreibung, die zugleich im Event-Filter-
        // Dropdown steht).
        ActivityFacade::useLog('auth')->event('marker_event_a')->log('');
        ActivityFacade::useLog('auth')->event('marker_event_b')->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterEvent', 'marker_event_a');

        $component->assertSee('marker_event_a');
        $component->assertDontSee('marker_event_b');
    }

    /**
     * Zeitpunkt-Range: `von`/`bis` sind beide tag-inklusiv. Drei Einträge in
     * unterschiedlichen Monaten (via `travelTo`), das Fenster Februar–April
     * lässt nur den März-Eintrag durch.
     */
    public function testFilterByDateRangeLimitsResults(): void
    {
        $admin = $this->makeAuditor();

        $this->travelTo(Carbon::parse('2026-01-15 09:00:00'), function (): void {
            ActivityFacade::useLog('test')->event('range_jan')->log('');
        });
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00'), function (): void {
            ActivityFacade::useLog('test')->event('range_mar')->log('');
        });
        $this->travelTo(Carbon::parse('2026-05-15 09:00:00'), function (): void {
            ActivityFacade::useLog('test')->event('range_mai')->log('');
        });

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterDateFrom', '2026-02-01');
        $component->set('filterDateTo', '2026-04-01');

        $component->assertSee('range_mar');
        $component->assertDontSee('range_jan');
        $component->assertDontSee('range_mai');
    }

    /**
     * Off-by-one-Regression: `created_at` liegt in UTC vor, gefiltert wird aber
     * nach Kalendertagen der Anzeige-Zeitzone. Die Datasets decken beide
     * Zonen-Offsets (Sommer +2, Winter +1) und die inklusiven Tagesgrenzen ab;
     * der alte `whereDate` auf dem UTC-Datumsteil verhielt sich an der lokalen
     * Mitternacht jeweils umgekehrt.
     */
    #[DataProvider('displayTimezoneBoundaryProvider')]
    public function testDateFilterUsesDisplayTimezoneBoundaries(
        string $createdAtUtc,
        string $filterField,
        string $filterValue,
        bool $shouldSee,
    ): void {
        $admin = $this->makeAuditor();

        $this->travelTo(Carbon::parse($createdAtUtc, 'UTC'), function (): void {
            ActivityFacade::useLog('test')->event('grenzfall')->log('');
        });

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set($filterField, $filterValue);

        if ($shouldSee) {
            $component->assertSee('grenzfall');
        } else {
            $component->assertDontSee('grenzfall');
        }
    }

    /**
     * Causer-Namenssuche über die polymorphe `causer`-Relation
     * (`whereHasMorph`): findet Einträge anhand des Verursacher-Namens, nicht
     * über einen FQCN-/ID-Vergleich.
     */
    public function testFilterByCauserNameSearchesAcrossMorph(): void
    {
        $admin = $this->makeAuditor();

        $causer = User::factory()->create(['name' => 'Gesuchter Verursacher']);
        $other = User::factory()->create(['name' => 'Irrelevanter Verursacher']);

        ActivityFacade::useLog('test')->event('demo')->causedBy($causer)->log('Tat des Gesuchten');
        ActivityFacade::useLog('test')->event('demo')->causedBy($other)->log('Tat des Anderen');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterCauser', 'Gesuchter');

        $component->assertSee('Gesuchter Verursacher');
        $component->assertDontSee('Irrelevanter Verursacher');
    }

    /**
     * Subject-Namenssuche über die polymorphe `subject`-Relation
     * (`whereHasMorph`) — Gegenstück zum Causer-Filter.
     */
    public function testFilterBySubjectNameSearchesAcrossMorph(): void
    {
        $admin = $this->makeAuditor();

        $subject = User::factory()->create(['name' => 'Gesuchtes Subjekt']);
        $other = User::factory()->create(['name' => 'Irrelevantes Subjekt']);

        ActivityFacade::useLog('test')->event('demo')->performedOn($subject)->log('Vorgang am Gesuchten');
        ActivityFacade::useLog('test')->event('demo')->performedOn($other)->log('Vorgang am Anderen');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterSubject', 'Gesuchtes');

        $component->assertSee('Gesuchtes Subjekt');
        $component->assertDontSee('Irrelevantes Subjekt');
    }

    /**
     * Regression: `%`/`_` im Suchbegriff sind LIKE-Wildcards und müssen als
     * Literale behandelt werden — sonst träfe `?causer=%` jede Zeile. SQLite
     * hat kein Default-Escape-Zeichen, daher die explizite ESCAPE-Klausel.
     */
    public function testNameFilterTreatsLikeWildcardsAsLiterals(): void
    {
        $admin = $this->makeAuditor();

        $match = User::factory()->create(['name' => '100%']);
        $miss = User::factory()->create(['name' => 'Alice']);

        ActivityFacade::useLog('test')->event('treffer_percent')->causedBy($match)->log('');
        ActivityFacade::useLog('test')->event('treffer_alice')->causedBy($miss)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterCauser', '%');

        $component->assertSee('treffer_percent');
        $component->assertDontSee('treffer_alice');
    }

    /**
     * Ergänzung zu `%`: Auch `_` (genau ein Zeichen) muss als Literal gelten —
     * sonst träfe die Suche nach `a_b` auch `axb`.
     */
    public function testNameFilterTreatsUnderscoreAsLiteral(): void
    {
        $admin = $this->makeAuditor();

        $match = User::factory()->create(['name' => 'a_b']);
        $miss = User::factory()->create(['name' => 'axb']);

        ActivityFacade::useLog('test')->event('treffer_underscore')->causedBy($match)->log('');
        ActivityFacade::useLog('test')->event('treffer_axb')->causedBy($miss)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterCauser', 'a_b');

        $component->assertSee('treffer_underscore');
        $component->assertDontSee('treffer_axb');
    }

    /**
     * Das Escape-Zeichen selbst muss escapt werden: Ein `\` im Suchbegriff darf
     * weder die ESCAPE-Klausel kapern noch (mangels Folge-Wildcard) eine
     * ungültige Escape-Sequenz erzeugen. `a\b` trifft nur `a\b`, nicht `axb`.
     */
    public function testNameFilterTreatsBackslashAsLiteral(): void
    {
        $admin = $this->makeAuditor();

        $match = User::factory()->create(['name' => 'a\\b']);
        $miss = User::factory()->create(['name' => 'axb']);

        ActivityFacade::useLog('test')->event('treffer_backslash')->causedBy($match)->log('');
        ActivityFacade::useLog('test')->event('treffer_axb_bs')->causedBy($miss)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterCauser', 'a\\b');

        $component->assertSee('treffer_backslash');
        $component->assertDontSee('treffer_axb_bs');
    }

    /**
     * Regression: Datumsfilter kommen via `#[Url]` auch als roher Query-String
     * (`?from=garbage`) und umgehen den `type="date"`-Constraint. Ein ungültiger
     * Wert wird als Fehler gemeldet und der Filter übersprungen — die Zeile
     * bleibt sichtbar, statt dass ein Unsinns-`whereDate` alles ausblendet.
     */
    public function testInvalidDateFromIsRejectedAndFilterSkipped(): void
    {
        $admin = $this->makeAuditor();

        $user = User::factory()->create();
        ActivityFacade::useLog('test')->event('treffer_from_invalid')->causedBy($user)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterDateFrom', 'garbage');

        $component->assertHasErrors('filterDateFrom');
        $component->assertSee('treffer_from_invalid');
    }

    /**
     * Wie der Von-Fall, aber für die Bis-Grenze — fängt eine Implementierung ab,
     * die nur `filterDateFrom` prüft.
     */
    public function testInvalidDateToIsRejectedAndFilterSkipped(): void
    {
        $admin = $this->makeAuditor();

        $user = User::factory()->create();
        ActivityFacade::useLog('test')->event('treffer_to_invalid')->causedBy($user)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterDateTo', '2026-13-40');

        $component->assertHasErrors('filterDateTo');
        $component->assertSee('treffer_to_invalid');
    }

    /**
     * Ein Bis-Datum vor dem Von-Datum ist widersprüchlich: Der Fehler hängt an
     * `Bis`, nur diese Grenze wird übersprungen; das gültige `Von` greift weiter.
     */
    public function testDateRangeRejectsToBeforeFrom(): void
    {
        $admin = $this->makeAuditor();

        $user = User::factory()->create();
        ActivityFacade::useLog('test')->event('treffer_range')->causedBy($user)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterDateFrom', '2000-01-02');
        $component->set('filterDateTo', '2000-01-01');

        $component->assertHasErrors('filterDateTo');
        $component->assertHasNoErrors('filterDateFrom');
        $component->assertSee('treffer_range');
    }

    /**
     * Gegenprobe: Ein gültiges Datum wird angewandt. Eine in der Zukunft liegende
     * Von-Grenze filtert die heutigen Einträge fehlerfrei heraus.
     */
    public function testValidDateFromIsApplied(): void
    {
        $admin = $this->makeAuditor();

        $user = User::factory()->create();
        ActivityFacade::useLog('test')->event('treffer_valid')->causedBy($user)->log('');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterDateFrom', '2999-01-01');

        $component->assertHasNoErrors('filterDateFrom');
        $component->assertDontSee('treffer_valid');
    }

    /**
     * Analog zum Sortier-Toggle muss jede Filter-Änderung (`updated`-Hook)
     * zurück auf Seite 1 springen, sonst bliebe der Cursor auf einer in der
     * gefilterten Treffermenge nicht mehr existierenden Seite stehen.
     */
    public function testChangingFilterResetsToFirstPage(): void
    {
        $admin = $this->makeAuditor();
        $actor = User::factory()->create();
        $this->actingAs($actor);

        for ($i = 0; $i < 30; $i++) {
            $subject = User::factory()->create();
            $subject->updateOrFail(['name' => self::RENAMED_PREFIX . $i]);
        }

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->call('gotoPage', 2);
        $component->assertSet('paginators.page', 2);

        $component->set('filterChannel', 'user');
        $component->assertSet('paginators.page', 1);
    }

    public function testResetFiltersClearsAllFilters(): void
    {
        $admin = $this->makeAuditor();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterChannel', 'auth');
        $component->set('filterEvent', 'logout');
        $component->set('filterCauser', 'irgendwer');
        $component->set('filterSubject', 'irgendwas');
        $component->set('filterDateFrom', '2026-01-01');
        $component->set('filterDateTo', '2026-12-31');

        $component->call('resetFilters');

        $component->assertSet('filterChannel', '');
        $component->assertSet('filterEvent', '');
        $component->assertSet('filterCauser', '');
        $component->assertSet('filterSubject', '');
        $component->assertSet('filterDateFrom', '');
        $component->assertSet('filterDateTo', '');
    }

    /**
     * Empty-State-Unterscheidung: Liefert ein aktiver Filter keine Treffer,
     * erscheint die „keine Treffer"-Meldung statt der „Log ist leer"-Meldung —
     * sonst wirkte ein zu eng gesetzter Filter wie ein leeres Log.
     */
    public function testEmptyResultDueToFilterShowsNoMatchesMessage(): void
    {
        $admin = $this->makeAuditor();

        ActivityFacade::useLog('test')->event('demo')->log('Irgendein Eintrag');

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->set('filterCauser', 'Garantiert Kein Treffer XYZ');

        $component->assertSee(__('app.activity_log_no_matches'));
        $component->assertDontSee(__('app.activity_log_empty'));
    }

    /**
     * Die Filterleiste ist standardmäßig eingeklappt und startet nur dann
     * aufgeklappt, wenn bereits gefiltert wird — sonst zeigte ein geteilter oder
     * gebookmarkter #[Url]-Filter eine gefilterte Liste ohne sichtbaren Grund.
     * Geprüft wird der serverseitig gerenderte Alpine-Initialzustand (`x-data`);
     * das Ein-/Ausklappen selbst ist rein clientseitiges Alpine-Verhalten.
     */
    public function testFilterPanelStartsCollapsedUnlessFiltersAreActive(): void
    {
        $admin = $this->makeAuditor();

        $component = Livewire::actingAs($admin)->test(ActivityLogTable::class); // @phpstan-ignore argument.templateType
        $component->assertSeeHtml('{ open: false }');

        $component->set('filterChannel', 'auth');
        $component->assertSeeHtml('{ open: true }');
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function displayTimezoneBoundaryProvider(): array
    {
        return [
            'Sommer (+2), from=Folgetag: sichtbar' => ['2026-06-20 23:00:00', 'filterDateFrom', '2026-06-21', true],
            'Sommer (+2), to=Vortag: ausgeblendet' => ['2026-06-20 23:00:00', 'filterDateTo', '2026-06-20', false],
            'Winter (+1), from=Folgetag: sichtbar' => ['2026-12-20 23:30:00', 'filterDateFrom', '2026-12-21', true],
            'Winter (+1), to=Vortag: ausgeblendet' => ['2026-12-20 23:30:00', 'filterDateTo', '2026-12-20', false],
            'untere Grenze exakt: sichtbar' => ['2026-06-19 22:00:00', 'filterDateFrom', '2026-06-20', true],
            'kurz vor unterer Grenze: ausgeblendet' => ['2026-06-19 21:59:59', 'filterDateFrom', '2026-06-20', false],
            'obere Grenze exakt: sichtbar' => ['2026-06-20 21:59:59', 'filterDateTo', '2026-06-20', true],
            'kurz nach oberer Grenze: ausgeblendet' => ['2026-06-20 22:00:00', 'filterDateTo', '2026-06-20', false],
        ];
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

    private function makeAuditor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

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
