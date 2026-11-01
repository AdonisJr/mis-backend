<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ADMIN_NAME = 'Admin';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedRoles();
        $this->seedAdmin();
    }

    private function seedRoles(): void
    {
        foreach (Role::STAFF_ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
    }

    private function seedAdmin(): void
    {
        $adminEmail = config('auth.seed_admin.email');
        $adminPassword = config('auth.seed_admin.password');

        if (empty($adminEmail) || empty($adminPassword)) {
            throw new RuntimeException('SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be set');
        }

        $adminUser = User::updateOrCreate(
            ['email' => $adminEmail],
            ['name' => self::ADMIN_NAME, 'password' => $adminPassword],
        );

        $adminUser->roles()->syncWithoutDetaching([Role::where('name', Role::ADMIN)->value('id')]);
    }
}
