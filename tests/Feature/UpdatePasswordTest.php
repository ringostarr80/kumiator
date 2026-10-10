<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\UpdatePasswordForm;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

final class UpdatePasswordTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    public function testPasswordCanBeUpdated(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->call('updatePassword');

        $refreshedUser = $user->fresh();
        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('new-password', $refreshedUser->password));
    }

    /**
     * Fremde Sitzungen beendet `AuthenticateSession` über den neuen Hash. Eine
     * Kopie des Cookies dieses Geräts trüge ihn mit und liefe weiter.
     */
    public function testPasswordChangeDiscardsTheOldSession(): void
    {
        $this->actingAs(User::factory()->create())->get('/user/profile')->assertOk();
        $oldSessionId = Session::getId();

        // Prüft mit, dass die alte Session gespeichert ist; sonst bewiese ihr
        // Fehlen danach nichts.
        $this->assertNotSame('', Session::getHandler()->read($oldSessionId));

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertNotSame($oldSessionId, Session::getId());
        $this->assertSame('', Session::getHandler()->read($oldSessionId));
    }

    public function testHttpPasswordUpdateLogsExactlyOnePasswordUpdatedEntry(): void
    {
        $this->actingAs(User::factory()->create());
        Activity::query()->delete();

        $this->put('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            Activity::query()->where('event', 'password_updated')->count(),
        );
    }

    public function testLivewirePasswordUpdateLogsExactlyOnePasswordUpdatedEntry(): void
    {
        $this->actingAs(User::factory()->create());
        Activity::query()->delete();

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertSame(
            1,
            Activity::query()->where('event', 'password_updated')->count(),
        );
    }

    public function testCurrentPasswordMustBeCorrect(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);

        $refreshedUser = $user->fresh();
        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('password', $refreshedUser->password));
    }

    /**
     * Ein leeres Feld prüft Laravel nur gegen implizite Regeln. Ohne `required`
     * liefe es an Länge und Bestätigung vorbei.
     */
    public function testNewPasswordIsRequired(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'password',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('updatePassword')
            ->assertHasErrors(['password' => 'required']);

        $refreshedUser = $user->fresh();
        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('password', $refreshedUser->password));
    }

    /**
     * Die bestätigte Sitzung tritt nur an Konten ohne Passwort-Login an die
     * Stelle des Passworts. Solange es gilt, bleibt es selbst der Nachweis.
     */
    public function testAConfirmedSessionDoesNotReplaceAValidCurrentPassword(): void
    {
        $this->confirmPassword();
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => '',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->call('updatePassword')
            ->assertHasErrors(['current_password' => 'required']);

        $refreshedUser = $user->fresh();
        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('password', $refreshedUser->password));
    }

    public function testNewPasswordsMustMatch(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'wrong-password',
            ])
            ->call('updatePassword')
            ->assertHasErrors(['password']);

        $refreshedUser = $user->fresh();
        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('password', $refreshedUser->password));
    }
}
