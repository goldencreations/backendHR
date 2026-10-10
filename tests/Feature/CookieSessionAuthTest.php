<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Cookie authentication for the browser clients.
 *
 * The frontend signs in against an allowlisted origin and is then identified
 * by an HttpOnly session cookie, so no credential is held in script-readable
 * storage. These tests cover what the API can genuinely assert in a feature
 * test: that a stateful sign-in issues an HttpOnly session cookie, that an
 * unlisted origin does not, and that stateful detection is restricted to the
 * configured origins.
 *
 * What cannot be asserted here, and why:
 *
 *  - CSRF rejection. VerifyCsrfToken returns early while runningUnitTests()
 *    is true, so a 419 is never produced and its absence proves nothing.
 *  - Session round-tripping. The test HTTP client does not replay response
 *    cookies into the next request (its cookie jar stays empty), and the auth
 *    guard is a container singleton shared across simulated requests. A test
 *    asserting "cookie alone authenticates /auth/me" would therefore pass on
 *    guard state rather than on the cookie, which is worse than no test.
 *
 * Both must be verified against a deployed environment with a real browser:
 * sign in, confirm the session cookie is HttpOnly, confirm the badge and any
 * write succeed with no token in storage, then confirm a tampered CSRF header
 * is rejected with 419.
 */
class CookieSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function hr(): User
    {
        return User::factory()->hrAdmin()->create([
            'email' => 'availamorand@gmail.com',
            'password' => Hash::make('Avil04'),
        ]);
    }

    protected function login(array $headers): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'availamorand@gmail.com',
            'password' => 'Avil04',
            'device_name' => 'web',
        ], $headers);
    }

    protected function browserHeaders(string $origin): array
    {
        return [
            'Origin' => $origin,
            'Accept' => 'application/json',
            'Referer' => $origin.'/',
        ];
    }

    protected function sessionCookies(TestResponse $response)
    {
        return collect($response->headers->getCookies())
            ->filter(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    }

    public function test_a_stateful_login_issues_an_http_only_session_cookie(): void
    {
        $this->hr();

        $response = $this->login($this->browserHeaders('https://hr.goldencreations.online'));

        $response->assertOk()->assertJsonPath('user.is_hr', true);

        $cookies = $this->sessionCookies($response);

        $this->assertTrue($cookies->isNotEmpty(), 'A stateful sign-in must issue a session cookie.');
        $this->assertTrue(
            $cookies->first()->isHttpOnly(),
            'The session cookie must be HttpOnly so page scripts cannot read it.'
        );
    }

    public function test_the_local_development_origin_also_receives_a_session(): void
    {
        $this->hr();

        $response = $this->login($this->browserHeaders('http://localhost:3000'));

        $response->assertOk();
        $this->assertTrue(
            $this->sessionCookies($response)->isNotEmpty(),
            'localhost:3000 must be recognised as stateful, or local sign-in breaks.'
        );
    }

    public function test_an_unknown_origin_receives_no_session(): void
    {
        $this->hr();

        $response = $this->login([
            'Origin' => 'https://evil.example.com',
            'Accept' => 'application/json',
        ]);

        // A bearer token is still issued, but a third-party site must never be
        // handed a session cookie it could ride on.
        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue(
            $this->sessionCookies($response)->isEmpty(),
            'An unlisted origin must not be issued a session cookie.'
        );
    }

    public function test_stateful_detection_is_limited_to_the_configured_origins(): void
    {
        $request = fn (string $origin) => Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['HTTP_ORIGIN' => $origin]
        );

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
            $request('https://hr.goldencreations.online')
        ));
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
            $request('http://localhost:3000')
        ));

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(
            $request('https://evil.example.com')
        ));
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(
            $request('http://localhost:3001')
        ), 'A different port is a different origin and must not inherit the session.');
    }

    public function test_cors_allows_credentials_for_the_configured_origins(): void
    {
        // Without this the browser silently discards the session cookie, which
        // presents as a login that succeeds then immediately signs out.
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertContains('api/*', config('cors.paths'));
        $this->assertContains('sanctum/csrf-cookie', config('cors.paths'));

        $origins = array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')));

        $this->assertContains('https://hr.goldencreations.online', $origins);
        $this->assertContains('http://localhost:3000', $origins);
    }
}
