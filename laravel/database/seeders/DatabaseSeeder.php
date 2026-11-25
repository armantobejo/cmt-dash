<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // No default users seeded here. Users are populated exclusively via
        // CmtAuth::seedFromEnv() (from CMT_USERS env), matching the Python port.
    }
}
