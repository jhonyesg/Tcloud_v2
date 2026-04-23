<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            'email' => 'jsuarez@mediaclouding.com',
            'password_hash' => '$2y$12$.i7K6LKe.EPhb0QXw.TmseWkrdPmQo6xH2n2U5vh4iXElgTWF0UD.',
            'role' => 'admin',
            'personal_quota_bytes' => 0,
            'personal_used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
