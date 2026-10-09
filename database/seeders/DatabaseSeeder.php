<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            // Creates the first HR administrator so the portal is usable
            // after a fresh deploy. No-ops when ADMIN_EMAIL is unset.
            AdminUserSeeder::class,
        ]);
    }
}
