<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\Activity;
use App\Models\User;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Exceptions\ProfilePhotoStorageException;
use App\Services\Upload\ProfilePhotoOptimizer;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function testCurrentProfileInformationIsAvailable(): void
    {
        $this->actingAs($user = User::factory()->create());

        $component = Livewire::test(UpdateProfileInformationForm::class);

        $componentState = $component->get('state');
        $this->assertIsArray($componentState);
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
            ->set('state', [
                'name' => 'Test Name',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ])
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
            ->call('updateProfileInformation')
            ->assertHasNoErrors('current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertEquals('Updated Name', $refreshedUser->name);
        $this->assertEquals($user->email, $refreshedUser->email);
        $this->assertNotNull($refreshedUser->email_verified_at);
    }

    public function testOwnEmailInDifferentCaseIsNotTreatedAsChange(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'john@example.com']));

        // Eigene Adresse nur in abweichender Schreibweise ist via NOCASE
        // identisch — kein Wechsel: weder `current_password` noch der
        // Deferred-Flow (Confirm-/Cancel-Mails) dürfen ausgelöst werden.
        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Neuer Name', 'email' => 'John@Example.COM'])
            ->call('updateProfileInformation')
            ->assertHasNoErrors('current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertSame('Neuer Name', $refreshedUser->name);
        $this->assertSame('john@example.com', $refreshedUser->email);
        $this->assertNull($refreshedUser->pending_email);
        Notification::assertNothingSent();
    }

    public function testEmailChangeWithoutCurrentPasswordIsRejected(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'original@example.com']));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => $user->name, 'email' => 'neu@example.com'])
            ->call('updateProfileInformation')
            ->assertHasErrors('current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertSame('original@example.com', $refreshedUser->email);
        $this->assertNull($refreshedUser->pending_email);
        Notification::assertNothingSent();
    }

    public function testEmailChangeWithWrongCurrentPasswordIsRejected(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'original@example.com']));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => 'neu@example.com',
                'current_password' => 'falsches-passwort',
            ])
            ->call('updateProfileInformation')
            ->assertHasErrors('current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertSame('original@example.com', $refreshedUser->email);
        $this->assertNull($refreshedUser->pending_email);
        Notification::assertNothingSent();
    }

    public function testCurrentPasswordIsClearedFromStateAfterEmailChangeRequest(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create());

        $component = Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => 'neu@example.com',
                'current_password' => 'password',
            ])
            ->call('updateProfileInformation');

        // Das Secret darf nach Erfolg nicht im Livewire-State (Snapshot landet
        // im HTML) zurück zum Client wandern.
        $state = $component->get('state');
        $this->assertIsArray($state);
        $this->assertArrayNotHasKey('current_password', $state);
        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
    }

    public function testHttpRouteRequiresCurrentPasswordForEmailChange(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'original@example.com']));

        // Der direkte Fortify-HTTP-Pfad teilt sich die Action mit dem
        // Livewire-Formular — die Re-Auth-Pflicht muss auch hier greifen.
        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
        ])->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');

        $this->assertNull($user->fresh()?->pending_email);
        Notification::assertNothingSent();
    }

    public function testHttpRouteAcceptsEmailChangeWithCorrectCurrentPassword(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'original@example.com']));

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
    }

    public function testProfileUpdateRollsBackAllWritesWhenEmailRequestFails(): void
    {
        Storage::fake('public');
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]));
        Activity::query()->delete();

        // Fehler im LETZTEN Schritt erzwingen: Der `pending_email`-Save in
        // `requestChange()` wirft. Ein echter Save-Hook, kein Mock. Foto, Name
        // und der Foto-Audit-Eintrag davor müssen per Transaktion zurückrollen.
        User::saving(function (User $model): void {
            if ($model->isDirty('pending_email')) {
                throw new \RuntimeException('boom beim pending_email-Save');
            }
        });

        try {
            app(UpdateUserProfileInformation::class)->update($user, [
                'name' => 'Geändert',
                'email' => 'neu@example.com',
                'current_password' => 'password',
                'photo' => UploadedFile::fake()->image('photo.jpg'),
            ]);
            $this->fail('Erwartete RuntimeException aus dem pending_email-Save.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Atomarität: KEINE der drei Schreibungen überlebt den Rollback.
        $this->assertSame('Original', $refreshed->name);
        $this->assertNull($refreshed->profile_photo_path);
        $this->assertNull($refreshed->pending_email);
        $this->assertSame(
            0,
            Activity::query()->where('event', 'profile_photo_updated')->count(),
        );

        // `ShouldQueueAfterCommit`: Bei Rollback geht keine Mail raus.
        Notification::assertNothingSent();
    }

    public function testFailedEmailRequestKeepsTheExistingPhotoFileAndRemovesTheNewOne(): void
    {
        // Profilfotos liegen auf der `public`-Disk; gezielt diese isolieren, damit
        // das Verzeichnis-Assert unten nur die Dateien dieses Tests sieht.
        Storage::fake('public');
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]));

        // Vorhandenes Foto aufsetzen, dessen Datei der Rollback NICHT anfassen darf.
        app(UpdateUserProfileInformation::class)->update($user, [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $existingPath = $user->fresh()?->profile_photo_path;
        $this->assertIsString($existingPath);
        Storage::disk('public')->assertExists($existingPath);

        Activity::query()->delete();

        // Fehler im LETZTEN Schritt erzwingen: Der `pending_email`-Save wirft.
        // Ein echter Save-Hook, kein Mock.
        User::saving(function (User $model): void {
            if ($model->isDirty('pending_email')) {
                throw new \RuntimeException('boom beim pending_email-Save');
            }
        });

        try {
            app(UpdateUserProfileInformation::class)->update($user, [
                'name' => 'Geändert',
                'email' => 'neu@example.com',
                'current_password' => 'password',
                'photo' => UploadedFile::fake()->image('second.jpg'),
            ]);
            $this->fail('Erwartete RuntimeException aus dem pending_email-Save.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // DB rollt auf das alte Foto zurück — und dessen Datei lebt noch (Avatar
        // bleibt heil), weil sie nicht synchron in der Transaktion gelöscht wird.
        $this->assertSame($existingPath, $refreshed->profile_photo_path);
        Storage::disk('public')->assertExists($existingPath);

        // Die neu geschriebene Datei wurde nach dem Rollback wieder entfernt:
        // im Verzeichnis liegt nur noch das alte Foto, kein verwaister Rest.
        $this->assertSame([$existingPath], Storage::disk('public')->files('profile-photos'));
    }

    /**
     * Ein fehlgeschlagener Foto-Upload (Platte voll / Storage-Defekt) darf nicht
     * still als Teilerfolg durchgehen: `storePublicly()` liefert auf der
     * `throw => false`-Disk `false`. Ohne explizite Behandlung würde daraus per
     * `?: null` „kein Foto" — Name/E-Mail committen, die UI meldet Erfolg, das
     * Foto fehlt kommentarlos und der Infra-Fehler erreicht kein Monitoring.
     * Erwartet: harter Fehlschlag ohne jeden Teil-Commit.
     */
    public function testFailedPhotoStorageAbortsTheWholeUpdate(): void
    {
        $this->actingAs($user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]));

        // `storePublicly()` liefert auf der `throw => false`-Disk `false`, wenn der
        // Datei-Schreibvorgang scheitert (Platte voll, S3 down). Diesen einen
        // Rückgabewert an der Disk-Grenze erzwingen: ein echter Schreibfehler ließe
        // sich nicht plattformstabil ohne Root-abhängige Rechte herstellen.
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturnFalse();
        Storage::set($user->profilePhotoDiskName(), $disk);

        Activity::query()->delete();

        $aborted = false;

        try {
            app(UpdateUserProfileInformation::class)->update($user, [
                'name' => 'Geändert',
                'email' => $user->email,
                'photo' => UploadedFile::fake()->image('photo.jpg'),
            ]);
        } catch (ProfilePhotoStorageException) {
            $aborted = true;
        }

        $this->assertTrue(
            $aborted,
            'Ein fehlgeschlagener Foto-Upload muss den gesamten Vorgang mit einer Exception abbrechen.',
        );

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Kein stiller Teilerfolg: Name NICHT committet, kein Foto-Pfad, kein Audit.
        $this->assertSame('Original', $refreshed->name);
        $this->assertNull($refreshed->profile_photo_path);
        $this->assertSame(
            0,
            Activity::query()->where('event', 'profile_photo_updated')->count(),
        );
    }

    public function testProfilePhotoCanBeUpdated(): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create());

        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertNotNull($refreshedUser->profile_photo_path);
        // Der Optimizer speichert das Foto als AVIF-Thumbnail, nicht das Original.
        $this->assertStringEndsWith('.avif', $refreshedUser->profile_photo_path);
        Storage::disk('public')->assertExists($refreshedUser->profile_photo_path);
        $storedDimensions = getimagesizefromstring(
            Storage::disk('public')->get($refreshedUser->profile_photo_path) ?? '',
        );
        $this->assertNotFalse($storedDimensions);
        $this->assertSame(256, $storedDimensions[0]);
        $this->assertSame(256, $storedDimensions[1]);

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

    /**
     * Der Optimizer legt das Thumbnail in einer tempnam()-Datei ab, die PHP nicht selbst wegräumt
     * (kein $_FILES-Upload). Ein erfolgreicher Upload darf danach keine `profile_photo_*`-Waise lassen.
     *
     * Der Optimizer bekommt dafür ein eigenes Verzeichnis: Im geteilten `sys_get_temp_dir()` zählte
     * die Prüfung bei parallelem Lauf die Zwischendateien fremder Prozesse mit.
     */
    public function testProfilePhotoUploadLeavesNoTempFileBehind(): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create());

        $directory = sys_get_temp_dir() . '/' . uniqid('profile-photo-', true);
        File::makeDirectory($directory);
        $this->app->instance(ProfilePhotoOptimizerContract::class, new ProfilePhotoOptimizer($directory));

        try {
            Livewire::test(UpdateProfileInformationForm::class)
                ->set('photo', UploadedFile::fake()->image('photo.jpg'))
                ->set('state', ['name' => $user->name, 'email' => $user->email])
                ->call('updateProfileInformation')
                ->assertHasNoErrors();

            $this->assertNotNull($user->fresh()?->profile_photo_path);
            $this->assertSame([], glob($directory . '/profile_photo_*'));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function testReplacingProfilePhotoLogsBothPaths(): void
    {
        Storage::fake('public');

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

        // Nach erfolgreichem Commit ist die alte Datei gelöscht, die neue da.
        $secondPath = $properties['profile_photo_path'];
        $this->assertIsString($secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function testProfilePhotoCanBeRemoved(): void
    {
        Storage::fake('public');

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
        Storage::fake('public');

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
        Storage::fake('public');
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

    public function testHugePixelImageIsRejectedWithFieldErrorInsteadOfServerError(): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create());

        // Eine Datei mit >25 MP im Header, aber winziger Größe besteht
        // `mimes:`+`max:` und löst erst im Optimizer den Bomben-Schutz aus. Der
        // Nutzer muss eine Feld-Validierung am `photo`-Feld sehen, keine
        // durchschlagende HTTP 500.
        $component = Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', $this->pngDeclaringDimensions(25_000_001, 1))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation');

        $component->assertHasErrors('photo');
        $component->assertSee(__('app.profile_photo_optimizer_too_many_pixels', ['max_megapixels' => 25]));

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

    #[DataProvider('acceptedProfilePhotoExtensionProvider')]
    public function testEachConfiguredExtensionIsAccepted(string $filename): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image($filename))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation')
            ->assertHasNoErrors('photo');

        // Optimizer hat das Foto persistiert (egal in welchem Quellformat).
        $this->assertIsString($user->fresh()?->profile_photo_path);
    }

    public function testUnacceptedExtensionIsRejected(): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create());

        // GIF steht nicht in der Default-`profile_photo_accepted_extensions`-
        // Liste und muss daher von der Validierung abgewiesen werden.
        Livewire::test(UpdateProfileInformationForm::class)
            ->set('photo', UploadedFile::fake()->image('photo.gif'))
            ->set('state', ['name' => $user->name, 'email' => $user->email])
            ->call('updateProfileInformation')
            ->assertHasErrors('photo');

        $this->assertNull($user->fresh()?->profile_photo_path);
    }

    public function testClientSideAcceptAttributeMatchesConfiguredExtensions(): void
    {
        // Bewusst andere Reihenfolge / mit Tippfehler-Variationen, um zu prüfen,
        // dass der Resolver normalisiert und die View die normalisierte Liste rendert.
        config(['jetstream.profile_photo_accepted_extensions' => ['JPG', 'png', '.webp']]);

        $this->actingAs(User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->assertSeeHtml('accept=".jpg,.png,.webp"');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedProfilePhotoExtensionProvider(): iterable
    {
        // Jeder Eintrag der Default-Config muss server-seitig durchgehen.
        yield 'jpg' => ['photo.jpg'];
        yield 'jpeg' => ['photo.jpeg'];
        yield 'png' => ['photo.png'];
        yield 'webp' => ['photo.webp'];
        yield 'avif' => ['photo.avif'];
    }

    /**
     * PNG, dessen IHDR-Header `$width`×`$height` deklariert, dessen Pixeldaten
     * aber von einem 1×1-Bild stammen: eine Dekompressions-Bombe, die
     * `mimes:`+`max:` besteht, aber den Pixel-Guard des Optimizers auslöst.
     */
    private function pngDeclaringDimensions(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor(1, 1);
        $this->assertInstanceOf(GdImage::class, $image);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        // IHDR: Breite ab Byte 16, Höhe ab Byte 20 (je 4 Bytes, big-endian).
        $png = substr_replace($png, pack('N2', $width, $height), 16, 8);

        return UploadedFile::fake()->createWithContent('photo.png', $png);
    }
}
