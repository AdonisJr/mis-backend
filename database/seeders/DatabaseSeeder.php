<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ADMIN_NAME = 'Admin';

    private const DEMO_PROGRAMS = [
        'STEM' => ['name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS],
        'ABM' => ['name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS],
        'HUMSS' => ['name' => 'Humanities and Social Sciences', 'stage' => GradeLevel::STAGE_SHS],
        'GAS' => ['name' => 'General Academic Strand', 'stage' => GradeLevel::STAGE_SHS],
        'TVL' => ['name' => 'Technical-Vocational-Livelihood', 'stage' => GradeLevel::STAGE_SHS],
        'BSIT' => ['name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE],
        'BSCS' => ['name' => 'Bachelor of Science in Computer Science', 'stage' => GradeLevel::STAGE_COLLEGE],
        'BSBA' => ['name' => 'Bachelor of Science in Business Administration', 'stage' => GradeLevel::STAGE_COLLEGE],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedRoles();
        $this->seedAdmin();
        $this->seedPrograms();
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

    private function seedPrograms(): void
    {
        // keyed on code and never overwriting, so re-seeding neither duplicates a program nor reverts an admin's edits
        foreach (self::DEMO_PROGRAMS as $programCode => $programAttributes) {
            Program::firstOrCreate(['code' => $programCode], $programAttributes);
        }
    }
}
