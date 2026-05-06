<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class EnsureUserIsApprovedMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_PATH = '/__test/approved-only';

    public function testApprovedUserPassesThrough(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(self::TEST_PATH)
            ->assertOk()
            ->assertSeeText('ok');
    }

    public function testUnapprovedUserIsRedirectedToRegistrationPending(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->actingAs($user)
            ->get(self::TEST_PATH)
            ->assertRedirect(route('registration.pending', absolute: false));
    }

    public function testUnapprovedUserExpectingJsonReceives403(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->actingAs($user)
            ->getJson(self::TEST_PATH)
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJson(['message' => __('app.registration_pending_message')]);
    }

    public function testGuestIsHandledByAuthMiddlewareNotByApprovedMiddleware(): void
    {
        // Sanity-Check: Approved-Middleware soll keinen anonymen Traffic blocken,
        // das ist Aufgabe des `auth`-Middleware. Guests werden dorthin
        // umgeleitet, nicht zu `/registration-pending`.
        $this->get(self::TEST_PATH)
            ->assertRedirect(route('login', absolute: false));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'approved'])
            ->get(self::TEST_PATH, static fn () => response('ok'));
    }
}
