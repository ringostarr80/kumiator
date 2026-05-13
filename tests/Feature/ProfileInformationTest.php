<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

final class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function testCurrentProfileInformationIsAvailable(): void
    {
        $this->actingAs($user = User::factory()->create());

        $component = Livewire::test(UpdateProfileInformationForm::class);

        $componentState = $component->get('state');
        assert(is_array($componentState));
        $this->assertEquals($user->name, $componentState['name']);
        $this->assertEquals($user->email, $componentState['email']);
    }

    public function testProfileInformationCanBeUpdated(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Test Name', 'email' => 'test@example.com'])
            ->call('updateProfileInformation');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        // Name wird unmittelbar übernommen — keine Bestätigung nötig.
        $this->assertEquals('Test Name', $refreshedUser->name);
        // E-Mail bleibt bis zur Bestätigung auf der alten Adresse (Deferred-Flow).
        $this->assertEquals('original@example.com', $refreshedUser->email);
        $this->assertEquals('test@example.com', $refreshedUser->pending_email);
        $this->assertNotNull($refreshedUser->email_verified_at);
    }

    public function testProfileNameCanBeUpdatedWithoutChangingEmail(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Updated Name', 'email' => $user->email])
            ->call('updateProfileInformation');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertEquals('Updated Name', $refreshedUser->name);
        $this->assertEquals($user->email, $refreshedUser->email);
        $this->assertNotNull($refreshedUser->email_verified_at);
    }

    public function testProfilePhotoCanBeUpdated(): void
    {
        Storage::fake();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertNotNull($refreshedUser->profile_photo_path);
    }
}
