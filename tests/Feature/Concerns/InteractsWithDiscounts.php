<?php

namespace Tests\Feature\Concerns;

use App\Models\DiscountType;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithDiscounts
{
    use InteractsWithFees;

    private const DISCOUNT_TYPES_URL = '/api/mis/discount-types';

    private const STUDENT_DISCOUNTS_URL = '/api/mis/student-discounts';

    private const SIBLING_TYPE = 'Sibling discount';

    private const SCHOLARSHIP_TYPE = 'Academic scholarship';

    private const VOUCHER_TYPE = 'Voucher';

    private const FIXED_LRN = '123456789012';

    private const DISCOUNT_TYPE_KEYS = ['id', 'name', 'description', 'source', 'value_type', 'value', 'base', 'stages', 'is_active', 'created_at', 'updated_at'];

    private const STUDENT_DISCOUNT_KEYS = ['id', 'student_id', 'school_year_id', 'discount_type_id', 'reference_no', 'remarks', 'created_at', 'updated_at', 'student', 'grade_level', 'discount_type'];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildTypePayload(array $overrides = []): array
    {
        return [
            'name' => self::SIBLING_TYPE,
            'description' => null,
            'source' => DiscountType::SOURCE_SCHOOL,
            'value_type' => DiscountType::VALUE_TYPE_PERCENT,
            'value' => 10,
            'base' => DiscountType::BASE_TUITION,
            'stages' => [GradeLevel::STAGE_JHS, GradeLevel::STAGE_SHS],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createType(array $overrides = []): DiscountType
    {
        return DiscountType::factory()->bothStages()->create($overrides);
    }

    /**
     * @param  array<string, mixed>  $studentFields
     */
    private function createEnrolledStudent(string $stage, string $status = Enrollment::STATUS_ENROLLED, array $studentFields = []): Student
    {
        $student = Student::factory()->create($studentFields);
        $this->enrollStudent($student, $stage, $status);

        return $student;
    }

    private function enrollStudent(Student $student, string $stage, string $status = Enrollment::STATUS_ENROLLED): Enrollment
    {
        $isJhs = $stage === GradeLevel::STAGE_JHS;
        $section = $isJhs
            ? Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id])
            : Section::factory()->create(['school_year_id' => $this->schoolYear->id]);
        $enrollmentFactory = $isJhs ? Enrollment::factory() : Enrollment::factory()->shs();

        if ($status !== Enrollment::STATUS_ENROLLED) {
            $enrollmentFactory = $enrollmentFactory->closed($status);
        }

        return $enrollmentFactory->create(['student_id' => $student->id, 'section_id' => $section->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAssignmentPayload(Student $student, DiscountType $discountType): array
    {
        return [
            'student_id' => $student->id,
            'school_year_id' => $this->schoolYear->id,
            'discount_type_id' => $discountType->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postType(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::DISCOUNT_TYPES_URL, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putType(int $typeId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::DISCOUNT_TYPES_URL.'/'.$typeId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAssignment(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::STUDENT_DISCOUNTS_URL, $payload);
    }

    private function assignType(Student $student, DiscountType $discountType): StudentDiscount
    {
        return StudentDiscount::factory()->create([
            'student_id' => $student->id,
            'school_year_id' => $this->schoolYear->id,
            'discount_type_id' => $discountType->id,
        ]);
    }

    /**
     * @return list<array<int, object>>
     */
    private function snapshotDiscountTables(): array
    {
        return [DB::table('discount_types')->orderBy('id')->get()->all(), DB::table('student_discounts')->orderBy('id')->get()->all()];
    }

    private function countQueriesWithoutTokens(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }
}
