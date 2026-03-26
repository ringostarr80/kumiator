<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string FEATURE_NOT_ENABLED_MESSAGE = 'Registration support is not enabled.';
    private const string REGISTER_URL_PATH = '/register';
    private const string TEST_EMAIL = 'john@example.com';

    public function testRegistrationScreenCanBeRendered(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        $response = $this->get(self::REGISTER_URL_PATH);

        $response->assertStatus(200);
    }

    public function testRegistrationScreenCannotBeRenderedIfSupportIsDisabled(): void
    {
        if (Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration support is enabled.');
        }

        $response = $this->get(self::REGISTER_URL_PATH);

        $response->assertStatus(404);
    }

    public function testNewUsersCanRegister(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        Role::findOrCreate('member');

        $response = $this->post(self::REGISTER_URL_PATH, [
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('member'));
    }

    public function testNewUsersAreNotApprovedByDefault(): void
    {
        if (! Features::enabled(Features::registration())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        Role::findOrCreate('member');

        $this->post(self::REGISTER_URL_PATH, [
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->approved_at);
    }
}
