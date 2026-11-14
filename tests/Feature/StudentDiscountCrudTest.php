<?php

namespace Tests\Feature;

use App\Models\DiscountType;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithDiscounts;
use Tests\TestCase;

class StudentDiscountCrudTest extends TestCase
{
    use InteractsWithDiscounts;
    use RefreshDatabase;

    private const STUDENT_SUMMARY_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_admin_assigns_discount_type_to_enrolled_student(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, studentFields: ['lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'value_type' => 'percent', 'value' => '10.00']);

        $createResponse = $this->postAssignment($this->buildAssignmentPayload($student, $discountType))
            ->assertCreated()
            ->assertJsonPath('student_discount.student_id', $student->id)
            ->assertJsonPath('student_discount.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('student_discount.discount_type_id', $discountType->id)
            ->assertJsonPath('student_discount.reference_no', null)
            ->assertJsonPath('student_discount.remarks', null)
            ->assertJsonPath('student_discount.student', ['id' => $student->id, 'lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz'])
            ->assertJsonPath('student_discount.grade_level.name', self::GRADE_7)
            ->assertJsonPath('student_discount.grade_level.stage', 'jhs')
            ->assertJsonPath('student_discount.discount_type', ['id' => $discountType->id, 'name' => self::SIBLING_TYPE, 'source' => 'school', 'value_type' => 'percent', 'value' => '10.00', 'base' => 'tuition', 'is_active' => true]);

        $this->assertSame(self::STUDENT_DISCOUNT_KEYS, array_keys($createResponse->json('student_discount')));
        $this->assertSame(self::STUDENT_SUMMARY_KEYS, array_keys($createResponse->json('student_discount.student')));
        $this->assertDatabaseCount('student_discounts', 1);
    }

    public function test_create_accepts_optional_reference_no_and_remarks(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        $this->postAssignment([...$this->buildAssignmentPayload($student, $this->createType()), 'reference_no' => 'REF-001', 'remarks' => 'Verified'])
            ->assertCreated()
            ->assertJsonPath('student_discount.reference_no', 'REF-001')
            ->assertJsonPath('student_discount.remarks', 'Verified');
    }

    public function test_oversized_reference_no_and_remarks_are_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $payload = $this->buildAssignmentPayload($student, $this->createType());

        $this->postAssignment([...$payload, 'reference_no' => str_repeat('r', 51), 'remarks' => str_repeat('m', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_no', 'remarks']);
        $this->assertDatabaseCount('student_discounts', 0);

        $this->postAssignment([...$payload, 'reference_no' => str_repeat('r', 50), 'remarks' => str_repeat('m', 500)])->assertCreated();
    }

    public function test_create_requires_valid_ids(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $validPayload = $this->buildAssignmentPayload($student, $this->createType());

        foreach (array_keys($validPayload) as $idKey) {
            $missingPayload = $validPayload;
            unset($missingPayload[$idKey]);
            $this->postAssignment($missingPayload)->assertUnprocessable()->assertJsonValidationErrorFor($idKey);

            foreach (['05', true, self::MISSING_ID] as $invalidId) {
                $this->postAssignment([...$validPayload, $idKey => $invalidId])->assertUnprocessable()->assertJsonValidationErrorFor($idKey);
            }
        }

        $this->assertDatabaseCount('student_discounts', 0);
    }

    public function test_admin_updates_reference_no_and_remarks(): void
    {
        $studentDiscount = StudentDiscount::factory()->create(['reference_no' => 'OLD', 'remarks' => 'Old remark']);

        $updateResponse = $this->putAssignment($studentDiscount->id, ['reference_no' => 'NEW-9', 'remarks' => 'New remark'])
            ->assertOk()
            ->assertJsonPath('student_discount.id', $studentDiscount->id)
            ->assertJsonPath('student_discount.reference_no', 'NEW-9')
            ->assertJsonPath('student_discount.remarks', 'New remark');

        $this->assertSame(self::STUDENT_DISCOUNT_KEYS, array_keys($updateResponse->json('student_discount')));

        $this->putAssignment($studentDiscount->id, [])->assertOk()->assertJsonPath('student_discount.reference_no', null)->assertJsonPath('student_discount.remarks', null);
        $this->assertNull($studentDiscount->fresh()->reference_no);
    }

    public function test_update_ignores_other_keys(): void
    {
        $studentDiscount = StudentDiscount::factory()->create(['reference_no' => 'KEEP']);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $this->putAssignment($studentDiscount->id, [
            'student_id' => Student::factory()->create()->id,
            'school_year_id' => $otherYear->id,
            'discount_type_id' => DiscountType::factory()->create()->id,
            'reference_no' => 'CHANGED',
        ])->assertOk();

        $storedDiscount = $studentDiscount->fresh();
        $this->assertSame($studentDiscount->student_id, $storedDiscount->student_id);
        $this->assertSame($studentDiscount->school_year_id, $storedDiscount->school_year_id);
        $this->assertSame($studentDiscount->discount_type_id, $storedDiscount->discount_type_id);
        $this->assertSame('CHANGED', $storedDiscount->reference_no);
    }

    public function test_update_validates_lengths(): void
    {
        $studentDiscount = StudentDiscount::factory()->create(['reference_no' => 'KEEP']);

        $this->putAssignment($studentDiscount->id, ['reference_no' => str_repeat('r', 51), 'remarks' => str_repeat('m', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_no', 'remarks']);

        $this->assertSame('KEEP', $studentDiscount->fresh()->reference_no);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->putAssignment(self::MISSING_ID, ['reference_no' => 'X'])->assertNotFound();
    }

    public function test_admin_removes_student_discount(): void
    {
        $studentDiscount = StudentDiscount::factory()->create();

        $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.$studentDiscount->id)->assertNoContent();

        $this->assertNull($studentDiscount->fresh());
        $this->assertNotNull($studentDiscount->student->fresh());
        $this->assertNotNull($studentDiscount->discountType->fresh());
    }

    public function test_removal_is_allowed_for_inactive_type_and_dropped_enrollment(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $discountType = $this->createType();
        $studentDiscount = $this->assignType($student, $discountType);
        $discountType->forceFill(['is_active' => false])->save();
        Enrollment::where('student_id', $student->id)->update(['status' => Enrollment::STATUS_DROPPED]);

        $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.$studentDiscount->id)->assertNoContent();

        $this->assertNull($studentDiscount->fresh());
    }

    public function test_delete_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_list_requires_school_year(): void
    {
        $this->withToken($this->adminToken)->getJson(self::STUDENT_DISCOUNTS_URL)->assertUnprocessable()->assertJsonValidationErrorFor('school_year_id');
    }

    public function test_list_returns_school_year_assignments_ordered_by_student_name(): void
    {
        $discountType = $this->createType();
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $zetaDiscount = $this->assignType(Student::factory()->create(['last_name' => 'Zeta', 'first_name' => 'Ana']), $discountType);
        $alphaDiscount = $this->assignType(Student::factory()->create(['last_name' => 'Alpha', 'first_name' => 'Zed']), $discountType);
        $alphaFirstDiscount = $this->assignType(Student::factory()->create(['last_name' => 'Alpha', 'first_name' => 'Amy']), $discountType);
        StudentDiscount::factory()->create(['school_year_id' => $otherYear->id, 'discount_type_id' => $discountType->id]);

        $listResponse = $this->fetchAssignmentList()->assertOk()->assertJsonCount(3, 'student_discounts');

        $this->assertSame([$alphaFirstDiscount->id, $alphaDiscount->id, $zetaDiscount->id], collect($listResponse->json('student_discounts'))->pluck('id')->all());
        $this->assertSame(self::STUDENT_DISCOUNT_KEYS, array_keys($listResponse->json('student_discounts.0')));
        $listResponse->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3]);
    }

    public function test_list_searches_student_name_and_lrn(): void
    {
        $discountType = $this->createType();
        $juanDiscount = $this->assignType(Student::factory()->create(['lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']), $discountType);
        $this->assignType(Student::factory()->create(), $discountType);

        foreach (['juan%20cruz', '1234567', 'DELA'] as $searchTerm) {
            $this->fetchAssignmentList('&search='.$searchTerm)->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('student_discounts.0.id', $juanDiscount->id);
        }

        $this->fetchAssignmentList('&search=juan%20nobody')->assertOk()->assertJsonCount(0, 'student_discounts');
    }

    public function test_list_filters_by_discount_type(): void
    {
        $siblingType = $this->createType(['name' => self::SIBLING_TYPE]);
        $scholarshipType = $this->createType(['name' => self::SCHOLARSHIP_TYPE]);
        $siblingDiscount = $this->assignType(Student::factory()->create(), $siblingType);
        $this->assignType(Student::factory()->create(), $scholarshipType);

        $this->fetchAssignmentList('&discount_type_id='.$siblingType->id)->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('student_discounts.0.id', $siblingDiscount->id);
    }

    public function test_list_filters_by_source(): void
    {
        $governmentDiscount = $this->assignType(Student::factory()->create(), $this->createType(['source' => 'government']));
        $schoolDiscount = $this->assignType(Student::factory()->create(), $this->createType(['source' => 'school']));

        $this->fetchAssignmentList('&source=government')->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('student_discounts.0.id', $governmentDiscount->id);
        $this->fetchAssignmentList('&source=school')->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('student_discounts.0.id', $schoolDiscount->id);
    }

    public function test_list_filters_by_student(): void
    {
        $student = Student::factory()->create();
        $studentDiscount = $this->assignType($student, $this->createType());
        $this->assignType(Student::factory()->create(), $this->createType());

        $this->fetchAssignmentList('&student_id='.$student->id)->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('student_discounts.0.id', $studentDiscount->id);
    }

    public function test_list_returns_type_terms_for_each_assignment(): void
    {
        $fixedType = $this->createType(['name' => 'Fixed grant', 'value_type' => 'fixed', 'value' => '9000.00', 'base' => 'tuition']);
        $percentType = $this->createType(['name' => 'Percent grant', 'value_type' => 'percent', 'value' => '25.00', 'base' => 'total']);
        $fixedDiscount = $this->assignType(Student::factory()->create(['last_name' => 'Aaa']), $fixedType);
        $percentDiscount = $this->assignType(Student::factory()->create(['last_name' => 'Bbb']), $percentType);

        $listResponse = $this->fetchAssignmentList()->assertOk();

        $listResponse->assertJsonPath('student_discounts.0.id', $fixedDiscount->id)
            ->assertJsonPath('student_discounts.0.discount_type.value_type', 'fixed')
            ->assertJsonPath('student_discounts.0.discount_type.value', '9000.00')
            ->assertJsonPath('student_discounts.0.discount_type.base', 'tuition')
            ->assertJsonPath('student_discounts.1.id', $percentDiscount->id)
            ->assertJsonPath('student_discounts.1.discount_type.value_type', 'percent')
            ->assertJsonPath('student_discounts.1.discount_type.value', '25.00')
            ->assertJsonPath('student_discounts.1.discount_type.base', 'total')
            ->assertJsonPath('student_discounts.0.grade_level', null);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->fetchAssignmentList('&search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrorFor('search');
        $this->fetchAssignmentList('&discount_type_id='.self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrorFor('discount_type_id');
        $this->fetchAssignmentList('&discount_type_id=abc')->assertUnprocessable()->assertJsonValidationErrorFor('discount_type_id');
        $this->fetchAssignmentList('&source=private')->assertUnprocessable()->assertJsonValidationErrorFor('source');
        $this->fetchAssignmentList('&student_id='.self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrorFor('student_id');
        $this->withToken($this->adminToken)->getJson(self::STUDENT_DISCOUNTS_URL.'?school_year_id='.self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrorFor('school_year_id');
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        StudentDiscount::factory()->count(16)->create(['school_year_id' => $this->schoolYear->id]);

        $this->fetchAssignmentList()->assertOk()->assertJsonCount(15, 'student_discounts')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
        $this->fetchAssignmentList('&page=2')->assertOk()->assertJsonCount(1, 'student_discounts')->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $this->assignEnrolledStudent();

        DB::enableQueryLog();
        $this->fetchAssignmentList()->assertOk()->assertJsonCount(1, 'student_discounts');
        $singleRowCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 3) as $rowNumber) {
            $this->assignEnrolledStudent();
        }

        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchAssignmentList()->assertOk()->assertJsonCount(4, 'student_discounts')->assertJsonPath('student_discounts.3.grade_level.stage', 'jhs');

        $this->assertSame($singleRowCount, $this->countQueriesWithoutTokens());
    }

    public function test_list_keeps_assignments_of_inactive_types(): void
    {
        $discountType = $this->createType();
        $studentDiscount = $this->assignType(Student::factory()->create(), $discountType);
        $discountType->forceFill(['is_active' => false])->save();

        $this->fetchAssignmentList()
            ->assertOk()
            ->assertJsonCount(1, 'student_discounts')
            ->assertJsonPath('student_discounts.0.id', $studentDiscount->id)
            ->assertJsonPath('student_discounts.0.discount_type.is_active', false);
    }

    private function assignEnrolledStudent(): StudentDiscount
    {
        return $this->assignType($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createType());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putAssignment(int $assignmentId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::STUDENT_DISCOUNTS_URL.'/'.$assignmentId, $payload);
    }

    private function fetchAssignmentList(string $queryString = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::STUDENT_DISCOUNTS_URL.'?school_year_id='.$this->schoolYear->id.$queryString);
    }
}
