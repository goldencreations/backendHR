<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $overrides = []): User
    {
        return User::factory()->create([
            'role' => User::ROLE_HR_ADMIN,
            'is_active' => true,
            'password' => Hash::make('correct-horse-battery'),
            ...$overrides,
        ]);
    }

    public function test_login_issues_a_token_and_returns_the_user(): void
    {
        $user = $this->admin(['email' => 'jordan@goldenhr.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jordan@goldenhr.com',
            'password' => 'correct-horse-battery',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'is_hr']])
            ->assertJsonPath('user.email', 'jordan@goldenhr.com')
            ->assertJsonPath('user.role', User::ROLE_HR_ADMIN)
            ->assertJsonPath('user.is_hr', true);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'phpunit',
        ]);
    }

    public function test_login_records_last_login_at(): void
    {
        $user = $this->admin(['email' => 'jordan@goldenhr.com']);
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/auth/login', [
            'email' => 'jordan@goldenhr.com',
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin(['email' => 'jordan@goldenhr.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'jordan@goldenhr.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * An unknown email must be indistinguishable from a wrong password so the
     * endpoint cannot be used to discover which addresses have accounts.
     */
    public function test_unknown_email_returns_the_same_message_as_a_bad_password(): void
    {
        $this->admin(['email' => 'jordan@goldenhr.com']);

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'nobody@goldenhr.com',
            'password' => 'whatever-password',
        ]);

        $badPassword = $this->postJson('/api/auth/login', [
            'email' => 'jordan@goldenhr.com',
            'password' => 'whatever-password',
        ]);

        $unknown->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(
            $badPassword->json('errors.email.0'),
            $unknown->json('errors.email.0')
        );
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        $this->admin(['email' => 'gone@goldenhr.com', 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'gone@goldenhr.com',
            'password' => 'correct-horse-battery',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->admin(['email' => 'jordan@goldenhr.com']);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'jordan@goldenhr.com');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The revoked token must no longer authenticate. Guards are reset
        // first because the auth manager caches the resolved user for the
        // lifetime of the test process; a real HTTP request gets a fresh one.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'not-the-password',
            'new_password' => 'a-brand-new-password',
            'new_password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_updates_the_password_and_keeps_the_caller_signed_in(): void
    {
        $user = $this->admin();
        $currentToken = $user->createToken('current-device')->plainTextToken;
        $otherToken = $user->createToken('other-device')->plainTextToken;

        $this->withToken($currentToken)->putJson('/api/auth/password', [
            'current_password' => 'correct-horse-battery',
            'new_password' => 'a-brand-new-password',
            'new_password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        $this->app['auth']->forgetGuards();

        // The current session survives; other sessions are revoked.
        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)->getJson('/api/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_password_must_be_confirmed(): void
    {
        $user = $this->admin();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'correct-horse-battery',
            'new_password' => 'a-brand-new-password',
            'new_password_confirmation' => 'something-different',
        ])->assertStatus(422)->assertJsonValidationErrors('new_password');
    }
}
