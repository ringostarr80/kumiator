<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ActivityLogTable;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    private function makeAdmin(): User
    {
        $permission = Permission::findOrCreate('activity-log.view');

        $admin = Role::findOrCreate('admin');
        $admin->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($admin);

        return $user;
    }
}
