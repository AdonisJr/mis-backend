<?php

namespace Tests\Feature;

use App\Models\DiscountType;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'admin@gmail.com';

    private const ADMIN_PASSWORD = '123';

    private const PROGRAMS_URL = '/api/mis/programs';

    private const TOKEN_NAME = 'test';

    private const DEMO_VALUE_NOTE = 'Placeholder value for demo data. Set the real amount before use.';

    private const EXPECTED_DEMO_DISCOUNT_TYPES = [
        'ESC grantee' => ['source' => 'government', 'value_type' => 'fixed', 'value' => '9000.00', 'base' => 'tuition', 'stages' => ['jhs']],
        'SHS voucher – full' => ['source' => 'government', 'value_type' => 'fixed', 'value' => '22500.00', 'base' => 'tuition', 'stages' => ['shs']],
        'SHS voucher – partial' => ['source' => 'government', 'value_type' => 'fixed', 'value' => '17500.00', 'base' => 'tuition', 'stages' => ['shs']],
        'Sibling discount' => ['source' => 'school', 'value_type' => 'percent', 'value' => '10.00', 'base' => 'tuition', 'stages' => ['jhs', 'shs']],
        'Academic scholarship' => ['source' => 'school', 'value_type' => 'percent', 'value' => '50.00', 'base' => 'tuition', 'stages' => ['jhs', 'shs']],
        'Employee dependent' => ['source' => 'school', 'value_type' => 'percent', 'value' => '25.00', 'base' => 'total', 'stages' => ['jhs', 'shs']],
    ];

    private const EXPECTED_DEMO_PROGRAMS = [
        'STEM' => ['name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS],
        'ABM' => ['name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS],
        'HUMSS' => ['name' => 'Humanities and Social Sciences', 'stage' => GradeLevel::STAGE_SHS],
        'GAS' => ['name' => 'General Academic Strand', 'stage' => GradeLevel::STAGE_SHS],
        'TVL' => ['name' => 'Technical-Vocational-Livelihood', 'stage' => GradeLevel::STAGE_SHS],
        'BSIT' => ['name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE],
        'BSCS' => ['name' => 'Bachelor of Science in Computer Science', 'stage' => GradeLevel::STAGE_COLLEGE],
        'BSBA' => ['name' => 'Bachelor of Science in Business Administration', 'stage' => GradeLevel::STAGE_COLLEGE],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.seed_admin.email' => self::ADMIN_EMAIL,
            'auth.seed_admin.password' => self::ADMIN_PASSWORD,
        ]);
    }

    public function test_seeder_creates_roles_and_admin(): void
    {
        $this->seed();

        $this->assertEqualsCanonicalizing(Role::STAFF_ROLES, Role::pluck('name')->all());
        $adminUser = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->assertTrue($adminUser->hasAnyRole([Role::ADMIN]));
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $adminUser->password));
        $this->assertFalse(User::where('email', 'test@example.com')->exists());
    }

    public function test_seeded_admin_can_log_in(): void
    {
        $this->seed();

        $this->postJson('/api/mis/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.roles', [Role::ADMIN]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(1, User::count());
        $this->assertSame(2, Role::count());
        $this->assertSame(1, DB::table('role_user')->count());
    }

    public function test_seeder_fails_fast_without_admin_config(): void
    {
        config(['auth.seed_admin.email' => null, 'auth.seed_admin.password' => null]);

        try {
            $this->seed();
            $this->fail('Seeder did not throw RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertSame('SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be set', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(0, DiscountType::count());
    }

    public function test_seeder_creates_demo_discount_types(): void
    {
        $this->seed();

        $this->assertSame(6, DiscountType::count());

        foreach (self::EXPECTED_DEMO_DISCOUNT_TYPES as $typeName => $expectedType) {
            $seededType = DiscountType::where('name', $typeName)->firstOrFail();
            $this->assertSame($expectedType['source'], $seededType->source);
            $this->assertSame($expectedType['value_type'], $seededType->value_type);
            $this->assertSame($expectedType['value'], $seededType->value);
            $this->assertSame($expectedType['base'], $seededType->base);
            $this->assertSame($expectedType['stages'], $seededType->stages);
            $this->assertSame(self::DEMO_VALUE_NOTE, $seededType->description);
            $this->assertTrue($seededType->is_active);
        }

        $this->assertFalse(DiscountType::pluck('name')->contains(fn (string $typeName) => str_contains(strtolower($typeName), 'full payment')));
    }

    public function test_reseeding_creates_no_duplicate_discount_types_and_keeps_edits(): void
    {
        $this->seed();
        $siblingType = DiscountType::where('name', 'Sibling discount')->firstOrFail();
        $siblingType->update(['value' => '15.00', 'description' => 'Edited']);
        $siblingType->forceFill(['is_active' => false])->save();

        $this->seed();

        $reseededType = DiscountType::where('name', 'Sibling discount')->firstOrFail();
        $this->assertSame('15.00', $reseededType->value);
        $this->assertSame('Edited', $reseededType->description);
        $this->assertFalse($reseededType->is_active);
        $this->assertSame(6, DiscountType::count());
    }

    public function test_seeder_creates_demo_programs(): void
    {
        $this->seed();

        foreach (self::EXPECTED_DEMO_PROGRAMS as $programCode => $expectedProgram) {
            $seededProgram = Program::where('code', $programCode)->firstOrFail();
            $this->assertSame($expectedProgram['name'], $seededProgram->name);
            $this->assertSame($expectedProgram['stage'], $seededProgram->stage);
            $this->assertNull($seededProgram->description);
            $this->assertTrue($seededProgram->is_active);
        }
    }

    public function test_seeder_adds_each_program_once(): void
    {
        $this->seed();

        $this->assertSame(8, Program::count());
        $expectedCodes = array_keys(self::EXPECTED_DEMO_PROGRAMS);
        sort($expectedCodes);
        $this->assertSame($expectedCodes, Program::pluck('code')->sort()->values()->all());
        $this->assertEachDemoProgramStoredOnce();
    }

    public function test_reseeding_creates_no_duplicate_programs(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(8, Program::count());
        $this->assertEachDemoProgramStoredOnce();
    }

    public function test_reseeding_keeps_admin_edits_to_programs(): void
    {
        $this->seed();
        $stemProgram = Program::where('code', 'STEM')->firstOrFail();
        $stemProgram->update(['name' => 'Renamed Strand']);
        $stemProgram->forceFill(['is_active' => false])->save();

        $this->seed();

        $reseededStem = Program::where('code', 'STEM')->firstOrFail();
        $this->assertSame('Renamed Strand', $reseededStem->name);
        $this->assertFalse($reseededStem->is_active);
        $this->assertSame(8, Program::count());
    }

    public function test_seeder_leaves_grade_levels_unchanged(): void
    {
        $gradeLevelsBefore = GradeLevel::orderBy('sort_order')->get(['id', 'name', 'stage', 'sort_order'])->toArray();

        $this->seed();

        $this->assertSame($gradeLevelsBefore, GradeLevel::orderBy('sort_order')->get(['id', 'name', 'stage', 'sort_order'])->toArray());
        $this->assertSame(10, GradeLevel::count());
    }

    public function test_seeded_programs_are_listed_by_code(): void
    {
        $this->seed();
        $adminToken = User::where('email', self::ADMIN_EMAIL)->firstOrFail()->createToken(self::TOKEN_NAME)->plainTextToken;

        $listResponse = $this->withToken($adminToken)
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 8);

        $this->assertSame(
            ['ABM', 'BSBA', 'BSCS', 'BSIT', 'GAS', 'HUMSS', 'STEM', 'TVL'],
            array_column($listResponse->json('programs'), 'code'),
        );
    }

    private function assertEachDemoProgramStoredOnce(): void
    {
        foreach (array_keys(self::EXPECTED_DEMO_PROGRAMS) as $programCode) {
            $this->assertSame(1, Program::where('code', $programCode)->count(), $programCode.' must be stored once');
        }
    }
}
