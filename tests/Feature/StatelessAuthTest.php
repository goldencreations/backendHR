<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bearer token authentication still works alongside cookie sessions.
 *
 * The browser clients now sign in with an HttpOnly session cookie (see
 * CookieSessionAuthTest), but non-browser callers still use a token, and the
 * token path must not regress while cookie auth is in place.
 *
 * These requests carry no Origin from a stateful host, or use an origin that
 * is not allowlisted, so Sanctum treats them as stateless and no CSRF token is
 * required. Note that VerifyCsrfToken is bypassed under runningUnitTests(), so
 * the absence of a 419 here is not evidence that CSRF is enforced; that is
 * checked against a deployed environment.
 */
class StatelessAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function headers(): array
    {
        return [
            'Origin' => 'https://hr.goldencreations.online',
            'Accept' => 'application/json',
        ];
    }

    public function test_cross_origin_login_is_not_rejected_for_a_missing_csrf_token(): void
    {
        User::factory()->hrAdmin()->create([
            'email' => 'availamorand@gmail.com',
            'password' => Hash::make('Avil04'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'availamorand@gmail.com',
            'password' => 'Avil04',
            'device_name' => 'web',
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('user.role', User::ROLE_HR_ADMIN)
            ->assertJsonPath('user.is_hr', true);

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotSame(419, $response->status());
    }

    public function test_a_bearer_only_request_needs_no_csrf_token(): void
    {
        $user = User::factory()->hrAdmin()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeaders($this->headers())
            ->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    /**
     * A write from the deployed frontend must also work without a session.
     */
    public function test_cross_origin_write_works_with_only_a_bearer_token(): void
    {
        $user = User::factory()->hrAdmin()->create();

        $response = $this->withHeaders($this->headers())
            ->withToken($user->createToken('web')->plainTextToken)
            ->postJson('/api/departments', ['name' => 'Design']);

        $response->assertCreated();
        $this->assertDatabaseHas('departments', ['name' => 'Design']);
    }
}
