<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Administrator',
            'created_at' => now(),
            'email' => 'admin@admin.com',
            'password' => Hash::make('admin@admin.com'),
        ]);

        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($role);
    }
}