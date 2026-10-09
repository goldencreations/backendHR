<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the first HR administrator so the portal can be signed into.
 *
 * Credentials come from the environment and are never hard-coded here. When
 * ADMIN_EMAIL is absent the seeder is a no-op, so a fresh deploy cannot
 * silently create a predictable account.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');

        if (! is_string($email) || $email === '') {
            return;
        }

        $plainPassword = env('ADMIN_PASSWORD');

        // Generate a password when none is supplied so the account is still
        // usable, and surface it once in the console rather than storing it.
        $generated = false;

        if (! is_string($plainPassword) || strlen($plainPassword) < 8) {
            $plainPassword = Str::password(16);
            $generated = true;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'HR Administrator'),
                // The User model casts password to 'hashed'.
                'password' => Hash::make($plainPassword),
                'role' => User::ROLE_HR_ADMIN,
                'is_active' => true,
            ]
        );

        $this->command?->info("HR administrator ready: {$user->email}");

        if ($generated) {
            $this->command?->warn("Generated password: {$plainPassword}");
            $this->command?->warn('Change it after the first sign-in.');
        }
    }
}
