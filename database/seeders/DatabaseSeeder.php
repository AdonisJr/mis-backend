<?php

namespace Database\Seeders;

use App\Models\DiscountType;
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

    private const DEMO_VALUE_NOTE = 'Placeholder value for demo data. Set the real amount before use.';

    private const DEMO_DISCOUNT_TYPES = [
        'ESC grantee' => ['source' => DiscountType::SOURCE_GOVERNMENT, 'value_type' => DiscountType::VALUE_TYPE_FIXED, 'value' => '9000.00', 'base' => DiscountType::BASE_TUITION, 'stages' => [GradeLevel::STAGE_JHS]],
        'SHS voucher – full' => ['source' => DiscountType::SOURCE_GOVERNMENT, 'value_type' => DiscountType::VALUE_TYPE_FIXED, 'value' => '22500.00', 'base' => DiscountType::BASE_TUITION, 'stages' => [GradeLevel::STAGE_SHS]],
        'SHS voucher – partial' => ['source' => DiscountType::SOURCE_GOVERNMENT, 'value_type' => DiscountType::VALUE_TYPE_FIXED, 'value' => '17500.00', 'base' => DiscountType::BASE_TUITION, 'stages' => [GradeLevel::STAGE_SHS]],
        'Sibling discount' => ['source' => DiscountType::SOURCE_SCHOOL, 'value_type' => DiscountType::VALUE_TYPE_PERCENT, 'value' => '10.00', 'base' => DiscountType::BASE_TUITION, 'stages' => DiscountType::STAGES],
        'Academic scholarship' => ['source' => DiscountType::SOURCE_SCHOOL, 'value_type' => DiscountType::VALUE_TYPE_PERCENT, 'value' => '50.00', 'base' => DiscountType::BASE_TUITION, 'stages' => DiscountType::STAGES],
        'Employee dependent' => ['source' => DiscountType::SOURCE_SCHOOL, 'value_type' => DiscountType::VALUE_TYPE_PERCENT, 'value' => '25.00', 'base' => DiscountType::BASE_TOTAL, 'stages' => DiscountType::STAGES],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedRoles();
        $this->seedAdmin();
        $this->seedPrograms();
        $this->seedDiscountTypes();
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

    private function seedDiscountTypes(): void
    {
        // keyed on name and never overwriting, so re-seeding neither duplicates a type nor reverts an admin's edits
        foreach (self::DEMO_DISCOUNT_TYPES as $typeName => $typeAttributes) {
            DiscountType::firstOrCreate(['name' => $typeName], [...$typeAttributes, 'description' => self::DEMO_VALUE_NOTE]);
        }
    }
}
