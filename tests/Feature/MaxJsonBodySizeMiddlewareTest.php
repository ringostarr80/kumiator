<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class MaxJsonBodySizeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const string AUTHENTICATE_URL = '/passkeys/authenticate';
    private const string REGISTER_URL = '/user/passkeys/register';

    public function testAuthenticateEndpointRejects413WhenBodyExceedsLimit(): void
    {
        $this->postJson(self::AUTHENTICATE_URL, [], ['Content-Type' => 'application/json'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY); // sanity: small body passes middleware

        $oversized = str_repeat('x', 65_537);

        $this->call('POST', self::AUTHENTICATE_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $oversized)
            ->assertStatus(Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
            ->assertJson(['message' => __('app.request_payload_too_large')]);
    }

    public function testRegisterEndpointRejects413WhenBodyExceedsLimit(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $oversized = str_repeat('x', 65_537);

        $this->actingAs($user)
            ->call('POST', self::REGISTER_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $oversized)
            ->assertStatus(Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
            ->assertJson(['message' => __('app.request_payload_too_large')]);
    }

    public function testAuthenticateEndpointRejects415ForNonJsonContentType(): void
    {
        $this->call('POST', self::AUTHENTICATE_URL, [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')
            ->assertStatus(Response::HTTP_UNSUPPORTED_MEDIA_TYPE)
            ->assertJson(['message' => __('app.unsupported_media_type')]);
    }

    public function testRegisterEndpointRejects415ForNonJsonContentType(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->call(
                'POST',
                self::REGISTER_URL,
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
                'foo=bar',
            )
            ->assertStatus(Response::HTTP_UNSUPPORTED_MEDIA_TYPE)
            ->assertJson(['message' => __('app.unsupported_media_type')]);
    }

    public function testAuthenticateEndpointRejects415WhenContentTypeIsMissing(): void
    {
        $this->call('POST', self::AUTHENTICATE_URL, [], [], [], [], '{}')
            ->assertStatus(Response::HTTP_UNSUPPORTED_MEDIA_TYPE)
            ->assertJson([
                'message' => __('app.unsupported_media_type'),
            ]);
    }

    public function testAuthenticateEndpointAllowsBodyAtExactLimit(): void
    {
        $atLimit = str_repeat('x', 65_536);

        // Must not be rejected by the middleware — the WebAuthn logic will reject it
        // for other reasons (invalid JSON / missing session), but not with 413.
        $response = $this->call(
            'POST',
            self::AUTHENTICATE_URL,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $atLimit,
        );

        $this->assertNotSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('passkey-authenticate');
        RateLimiter::clear('passkey-register');
    }
}
