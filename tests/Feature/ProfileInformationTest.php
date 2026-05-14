<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
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

        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertNotNull($refreshedUser->profile_photo_path);

        $entry = Activity::query()
            ->where('log_name', 'user')
            ->where('event', 'profile_photo_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(__('app.activity_profile_photo_updated'), $entry->description);
        $this->assertSame($user->getKey(), $entry->causer_id);
        $this->assertSame('user', $entry->causer_type);
        $this->assertSame($user->getKey(), $entry->subject_id);
        $this->assertSame('user', $entry->subject_type);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame($refreshedUser->profile_photo_path, $properties['profile_photo_path'] ?? null);
        $this->assertArrayHasKey('previous_profile_photo_path', $properties);
        $this->assertNull($properties['previous_profile_photo_path']);
    }

    public function testReplacingProfilePhotoLogsBothPaths(): void
    {
        Storage::fake();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('first.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $firstPath = $user->fresh()?->profile_photo_path;
        $this->assertIsString($firstPath);

        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('second.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $entry = Activity::query()
            ->where('log_name', 'user')
            ->where('event', 'profile_photo_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame($firstPath, $properties['previous_profile_photo_path'] ?? null);
        $this->assertNotNull($properties['profile_photo_path'] ?? null);
        $this->assertNotSame($firstPath, $properties['profile_photo_path']);
    }

    public function testProfilePhotoCanBeRemoved(): void
    {
        Storage::fake();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $previousPath = $user->fresh()?->profile_photo_path;
        $this->assertIsString($previousPath);

        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->call('deleteProfilePhoto');

        $refreshedUser = $user->fresh();
        $this->assertNull($refreshedUser->profile_photo_path);

        $entry = Activity::query()
            ->where('log_name', 'user')
            ->where('event', 'profile_photo_removed')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(__('app.activity_profile_photo_removed'), $entry->description);
        $this->assertSame($user->getKey(), $entry->causer_id);
        $this->assertSame('user', $entry->causer_type);
        $this->assertSame($user->getKey(), $entry->subject_id);
        $this->assertSame('user', $entry->subject_type);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame($previousPath, $properties['previous_profile_photo_path'] ?? null);
    }

    public function testRemovingNonexistentProfilePhotoDoesNotLog(): void
    {
        Storage::fake();

        $this->actingAs($user = User::factory()->create());

        $this->assertNull($user->profile_photo_path);

        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->call('deleteProfilePhoto');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'profile_photo_removed')
                ->count(),
        );
    }

    public function testProfilePhotoUploadLimitIsDisplayed(): void
    {
        // 1 MB liegt unter dem PHP-Limit der Testumgebung — die App-Config
        // ist damit das bindende Limit und der Server-Hinweis bleibt aus.
        config(['jetstream.profile_photo_max_kilobytes' => 1_024]);

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(UpdateProfileInformationForm::class);
        $component->assertSee(__('app.profile_photo_max_size', ['size' => '1 MB']));
        $component->assertDontSee(__('app.profile_photo_limited_by_server'));
    }

    public function testProfilePhotoUploadLimitShowsServerHintWhenConstrainedByServer(): void
    {
        // 1 GB liegt garantiert über den PHP-/Livewire-Limits — eine
        // Server-Einstellung ist dann das bindende Limit.
        config(['jetstream.profile_photo_max_kilobytes' => 1_048_576]);

        $this->actingAs(User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->assertSee(__('app.profile_photo_limited_by_server'));
    }

    public function testProfilePhotoExceedingTheEffectiveLimitIsRejected(): void
    {
        Storage::fake();
        config(['jetstream.profile_photo_max_kilobytes' => 1_024]);

        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('too-big.jpg')->size(2_048))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        // Validierung greift mit dem effektiven Limit — das Foto wird nicht
        // persistiert.
        $this->assertNull($user->fresh()?->profile_photo_path);
    }

    public function testInfrastructureUploadFailureShowsTheEffectiveLimitInTheErrorMessage(): void
    {
        // 1 MB liegt unter dem PHP-Limit der Testumgebung — damit ist das
        // effektive Limit deterministisch 1 MB.
        config(['jetstream.profile_photo_max_kilobytes' => 1_024]);

        $this->actingAs(User::factory()->create());

        // $errorsInJson === null simuliert einen Infrastruktur-Fehler (Nginx
        // 413, PHP post_max_size) — kein durchgereichter Laravel-Validierungs-
        // fehler. Der Override ersetzt die generische Meldung.
        $component = Livewire::test(UpdateProfileInformationForm::class);
        $component->call('_uploadErrored', 'photo', null, false);
        $component->assertHasErrors('photo');
        $component->assertSee(__('app.profile_photo_upload_failed', ['size' => '1 MB']));
    }

    public function testJsonUploadErrorIsPassedThroughUnchanged(): void
    {
        config(['jetstream.profile_photo_max_kilobytes' => 1_024]);

        $this->actingAs(User::factory()->create());

        // Mit gesetztem $errorsInJson stammt der Fehler aus Livewires eigener
        // Temp-Upload-Validierung — der Override reicht ihn unverändert an den
        // Parent durch, unsere angereicherte Meldung darf NICHT erscheinen.
        $passthroughMessage = 'Livewire-Temp-Upload-Validierungsfehler';
        $errorsInJson = json_encode(['errors' => ['files.0' => [$passthroughMessage]]]);
        $this->assertIsString($errorsInJson);

        $component = Livewire::test(UpdateProfileInformationForm::class);
        $component->call('_uploadErrored', 'photo', $errorsInJson, false);
        $component->assertHasErrors('photo');
        $component->assertSee($passthroughMessage);
        $component->assertDontSee(__('app.profile_photo_upload_failed', ['size' => '1 MB']));
    }

    public function testLivewireTemporaryUploadLimitMatchesProfilePhotoConfig(): void
    {
        // Der JetstreamServiceProvider gleicht Livewires globales Temp-Upload-
        // Limit beim Booten an die Profilfoto-Config (Default 8192 KB) an.
        $this->assertSame(
            ['required', 'file', 'max:8192'],
            config('livewire.temporary_file_upload.rules'),
        );
    }
}
