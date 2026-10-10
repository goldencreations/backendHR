<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token authentication for both portals.
 *
 * A successful login returns the user plus their linked employee record, so
 * the frontend can decide between the HR and employee portal without a
 * second request.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->email)
            ->first();

        // One generic message for unknown email and wrong password so the
        // endpoint cannot be used to enumerate accounts.
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        /*
         * Browser clients authenticate with an HttpOnly session cookie.
         * Sanctum only treats a request as stateful when the Origin matches
         * SANCTUM_STATEFUL_DOMAINS, so the web guard is engaged for those and
         * the session cookie is issued. Other callers (tests, scripts) keep
         * working through the bearer token returned alongside it.
         */
        $token = $user->createToken($request->deviceName() ?: 'api')->plainTextToken;

        if (EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // A cookie session resolves to a TransientToken, which has no delete().
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        /*
         * Bearer clients revoke their token above. Cookie clients have no
         * token to delete, so the session is invalidated and its cookie
         * cleared or the browser would stay signed in after signing out.
         */
        if (EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Password change requires the current password. The employee form's
     * "reset portal password" is an HR-only action and is not exposed here.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $request->new_password])->save();

        /*
         * End other active sessions so a password change does not leave an
         * old session usable, while keeping the caller signed in. Cookie
         * clients have no token rows, so revoking tokens is skipped for them
         * and the current session is left intact.
         */
        if ($request->user()->currentAccessToken() instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        } else {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Password updated.']);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('employee.department', 'employee.jobRole');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_hr' => $user->isHr(),
            'employee' => $user->employee ? [
                'id' => $user->employee->id,
                'employee_code' => $user->employee->employee_code,
                'first_name' => $user->employee->first_name,
                'last_name' => $user->employee->last_name,
                'full_name' => $user->employee->full_name,
                'employment_type' => $user->employee->employment_type,
                'profile_image_path' => $user->employee->profile_image_path,
                'department' => $user->employee->department?->name,
                'job_role' => $user->employee->jobRole?->title,
            ] : null,
        ];
    }
}
