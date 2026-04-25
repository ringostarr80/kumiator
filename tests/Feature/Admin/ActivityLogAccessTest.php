<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ActivityLogTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $actor = User::factory()->create(['name' => 'Acting Admin']);
        $subject = User::factory()->create(['name' => 'Subject User']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => 'Subject Renamed']);

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk()
            ->assertSee('Acting Admin')
            ->assertSee('Subject Renamed');
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
        $actor = User::factory()->create(['name' => 'Acting Admin']);
        $subject = User::factory()->create(['name' => 'Subject User']);

        $this->actingAs($actor);
        $subject->updateOrFail(['name' => 'Subject Renamed']);

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
        $actor = User::factory()->create(['name' => 'Acting Admin']);
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
