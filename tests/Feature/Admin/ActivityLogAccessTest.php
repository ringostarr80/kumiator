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
