<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Simple',
            'created_at' => now(),
            'email' => 'simple@simple.com',
            'password' => Hash::make('simple@simple.com'),
        ]);

        $role = Role::create(['name' => 'user', 'guard_name' => 'web']);
        $user->assignRole($role);
    }
}