<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function testOtherBrowserSessionsCanBeLoggedOut(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(LogoutOtherBrowserSessionsForm::class)
            ->set('password', 'password')
            ->call('logoutOtherBrowserSessions')
            ->assertSuccessful();
    }
}
