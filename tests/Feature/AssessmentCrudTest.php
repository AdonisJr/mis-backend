<?php

namespace Tests\Feature;

use App\Http\Controllers\AssessmentController;
use App\Models\Assessment;
use App\Models\AssessmentDiscount;
use App\Models\AssessmentFeeLine;
use App\Models\AssessmentInstallment;
use App\Models\FeeItem;
use App\Models\FeeScheduleLine;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\AssessmentCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithAssessments;
use Tests\TestCase;

class AssessmentCrudTest extends TestCase
{
    use InteractsWithAssessments;
    use RefreshDatabase;

    private const ACTING_ADMIN_EMAIL = 'acting@admin.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_assessment_tables_have_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'student_id', 'school_year_id', 'grade_level_id', 'program_id', 'payment_plan_id', 'status', 'plan_name', 'adjustment_kind', 'adjustment_type', 'adjustment_value', 'adjustment_amount',
            'gross_tuition', 'gross_miscellaneous', 'gross_other', 'gross_total', 'discount_tuition', 'discount_miscellaneous', 'discount_other', 'discount_total', 'net_total',
            'created_by', 'superseded_by', 'superseded_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('assessments'));
        $this->assertEqualsCanonicalizing(['id', 'assessment_id', 'fee_item_name', 'category', 'amount', 'created_at', 'updated_at'], Schema::getColumnListing('assessment_fee_lines'));
        $this->assertEqualsCanonicalizing(
            ['id', 'assessment_id', 'discount_type_name', 'source', 'value_type', 'value', 'base', 'reference_no', 'computed_amount', 'applied_amount', 'created_at', 'updated_at'],
            Schema::getColumnListing('assessment_discounts'),
        );
        $this->assertEqualsCanonicalizing(['id', 'assessment_id', 'sequence', 'label', 'due_date', 'amount', 'created_at', 'updated_at'], Schema::getColumnListing('assessment_installments'));
    }

    public function test_constraints_restrict_deletes_and_null_plan_on_delete(): void
    {
        $assessment = Assessment::factory()->create();
        AssessmentFeeLine::create(['assessment_id' => $assessment->id, 'fee_item_name' => self::TUITION_FEE, 'category' => 'tuition', 'amount' => '1.00']);
        AssessmentDiscount::create(['assessment_id' => $assessment->id, 'discount_type_name' => 'Grant', 'source' => 'school', 'value_type' => 'fixed', 'value' => '1.00', 'base' => 'tuition', 'computed_amount' => '1.00', 'applied_amount' => '1.00']);
        AssessmentInstallment::create(['assessment_id' => $assessment->id, 'sequence' => 1, 'label' => 'Full payment', 'due_date' => self::ASSESSED_ON, 'amount' => '1.00']);

        foreach ([['students', $assessment->student_id], ['school_years', $assessment->school_year_id], ['grade_levels', $assessment->grade_level_id]] as [$table, $rowId]) {
            try {
                DB::table($table)->where('id', $rowId)->delete();
                $this->fail('Deleting from '.$table.' should be restricted.');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $rowId]);
            }
        }

        PaymentPlan::whereKey($assessment->payment_plan_id)->delete();
        $this->assertNull($assessment->fresh()->payment_plan_id);
        $this->assertSame($assessment->plan_name, $assessment->fresh()->plan_name);

        $assessment->delete();
        $this->assertDatabaseCount('assessment_fee_lines', 0);
        $this->assertDatabaseCount('assessment_discounts', 0);
        $this->assertDatabaseCount('assessment_installments', 0);
    }

    public function test_preview_returns_unsaved_snapshot_and_writes_nothing(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15']);
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $snapshot = $this->snapshotAssessmentTables();

        $previewResponse = $this->postPreview($this->buildAssessmentPayload($student, $paymentPlan))->assertOk();

        $preview = $previewResponse->json('assessment');
        $this->assertSame(self::ASSESSMENT_KEYS, array_keys($preview));
        $this->assertSame([null, null, null, null, null], array_values(Arr::only($preview, self::ASSESSMENT_NULL_KEYS)));
        $this->assertSame('5000.00', $preview['net_total']);
        $this->assertSame(['id' => User::where('email', self::ACTING_ADMIN_EMAIL)->value('id'), 'name' => User::where('email', self::ACTING_ADMIN_EMAIL)->value('name')], $preview['created_by']);
        $this->assertCount(2, $preview['schedule']);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_preview_matches_created_assessment(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15'], ['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '250.00']);
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00'], [$this->createFeeItem(self::LIBRARY_FEE), '300.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Grant', 'value' => '500.00']));
        $payload = $this->buildAssessmentPayload($student, $paymentPlan);

        $preview = $this->postPreview($payload)->assertOk()->json('assessment');
        $created = $this->postAssessment($payload)->assertCreated()->json('assessment');

        $this->assertSame(Arr::except($created, self::ASSESSMENT_NULL_KEYS), Arr::except($preview, self::ASSESSMENT_NULL_KEYS));
        $this->assertNotNull($created['id']);
        $this->assertSame(Assessment::STATUS_CURRENT, $created['status']);
    }

    public function test_admin_creates_assessment_with_full_snapshot(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15']);
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00'], [$this->createFeeItem(self::LIBRARY_FEE), '300.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, studentFields: ['lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);
        $this->assignType($student, $this->createType(['name' => 'Grant', 'value' => '500.00']));

        $response = $this->postAssessment($this->buildAssessmentPayload($student, $paymentPlan))
            ->assertCreated()
            ->assertJsonPath('assessment.status', 'current')
            ->assertJsonPath('assessment.student', ['id' => $student->id, 'lrn' => self::FIXED_LRN, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz'])
            ->assertJsonPath('assessment.school_year', ['id' => $this->schoolYear->id, 'name' => '2030-2031'])
            ->assertJsonPath('assessment.grade_level.name', self::GRADE_7)
            ->assertJsonPath('assessment.program', null)
            ->assertJsonPath('assessment.plan', ['name' => self::MONTHLY_PLAN, 'adjustment' => null])
            ->assertJsonPath('assessment.gross_total', '5300.00')
            ->assertJsonPath('assessment.discount_total', '500.00')
            ->assertJsonPath('assessment.net_total', '4800.00')
            ->assertJsonPath('assessment.superseded_at', null)
            ->assertJsonPath('assessment.superseded_by', null);

        $this->assertSame(self::ASSESSMENT_KEYS, array_keys($response->json('assessment')));
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('assessment_fee_lines', 2);
        $this->assertDatabaseCount('assessment_discounts', 1);
        $this->assertDatabaseCount('assessment_installments', 2);
    }

    public function test_create_records_creator_and_date(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $admin = User::where('email', self::ACTING_ADMIN_EMAIL)->first();

        $assessment = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());

        $this->assertSame(['id' => $admin->id, 'name' => $admin->name], $assessment['created_by']);
        $this->assertStringStartsWith(self::ASSESSED_ON, $assessment['created_at']);
        $this->assertSame($admin->id, Assessment::first()->created_by);
    }

    public function test_snapshot_is_unchanged_after_setup_edits(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15'], ['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '250.00']);
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $this->createJhsSchedule([[$tuitionItem, '5000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $discountType = $this->createType(['name' => 'Grant', 'value' => '500.00']);
        $this->assignType($student, $discountType);
        $created = $this->assessStudent($student, $paymentPlan);

        FeeScheduleLine::query()->update(['amount' => '9999.00']);
        $tuitionItem->update(['name' => 'Renamed fee']);
        $discountType->update(['name' => 'Renamed grant', 'value' => '1.00']);
        $paymentPlan->forceFill(['adjustment_kind' => null, 'adjustment_type' => null, 'adjustment_value' => null, 'name' => 'Renamed plan'])->save();
        $paymentPlan->installments()->update(['due_date' => '2031-01-01']);

        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/'.$created['id'])->assertOk()->assertExactJson(['assessment' => $created]);
    }

    public function test_show_returns_snapshot(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $created = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());

        $showResponse = $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/'.$created['id'])->assertOk();

        $this->assertSame(self::ASSESSMENT_KEYS, array_keys($showResponse->json('assessment')));
        $this->assertSame($created, $showResponse->json('assessment'));
    }

    public function test_show_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_reassess_supersedes_current_and_creates_new(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $paymentPlan = $this->createCashPlan();
        $first = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $paymentPlan);
        $admin = User::where('email', self::ACTING_ADMIN_EMAIL)->first();

        $second = $this->postReassess($first['id'], $paymentPlan)->assertCreated()->json('assessment');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('current', $second['status']);
        $oldRow = Assessment::find($first['id']);
        $this->assertSame(Assessment::STATUS_SUPERSEDED, $oldRow->status);
        $this->assertNotNull($oldRow->superseded_at);
        $this->assertSame($admin->id, $oldRow->superseded_by);
        $this->assertSame(1, Assessment::current()->count());
        $this->assertSame(2, Assessment::count());
    }

    public function test_reassess_with_another_plan(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $first = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        $installmentPlan = $this->createInstallmentPlan(['2030-08-15']);

        $second = $this->postReassess($first['id'], $installmentPlan)->assertCreated()->json('assessment');

        $this->assertSame($installmentPlan->id, $second['payment_plan_id']);
        $this->assertSame(self::MONTHLY_PLAN, $second['plan']['name']);
        $this->assertSame(['Down payment', 'Installment 1'], array_column($second['schedule'], 'label'));
    }

    public function test_reassess_superseded_assessment_returns_422(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $paymentPlan = $this->createCashPlan();
        $first = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $paymentPlan);
        $this->postReassess($first['id'], $paymentPlan)->assertCreated();
        $snapshot = $this->snapshotAssessmentTables();

        $this->postReassess($first['id'], $paymentPlan)->assertUnprocessable()->assertExactJson(['message' => AssessmentController::SUPERSEDED_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_reassess_missing_id_returns_404(): void
    {
        $this->postReassess(self::MISSING_ID, $this->createCashPlan())->assertNotFound();
    }

    public function test_reassess_validates_plan(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $first = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        $url = self::ASSESSMENTS_URL.'/'.$first['id'].'/reassess';
        $otherYearPlan = PaymentPlan::factory()->create(['school_year_id' => SchoolYear::factory()->create(self::SCHOOL_YEAR_2031)->id]);
        $snapshot = $this->snapshotAssessmentTables();

        $this->withToken($this->adminToken)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['payment_plan_id']);
        $this->withToken($this->adminToken)->postJson($url, ['payment_plan_id' => self::MISSING_ID])->assertUnprocessable()->assertJsonValidationErrors(['payment_plan_id']);
        $this->postReassess($first['id'], PaymentPlan::factory()->inactive()->create(['school_year_id' => $this->schoolYear->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_plan_id.0', AssessmentCalculator::PLAN_INACTIVE_MESSAGE);
        $this->postReassess($first['id'], $otherYearPlan)
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_plan_id.0', AssessmentCalculator::PLAN_OTHER_YEAR_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_assessment_has_no_update_or_delete_route(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $created = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        $snapshot = $this->snapshotAssessmentTables();
        $url = self::ASSESSMENTS_URL.'/'.$created['id'];

        $this->withToken($this->adminToken)->putJson($url, ['net_total' => '0.00'])->assertStatus(405);
        $this->withToken($this->adminToken)->deleteJson($url)->assertStatus(405);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_history_lists_current_and_superseded_newest_first(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $paymentPlan = $this->createCashPlan();
        $first = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $paymentPlan);
        $second = $this->postReassess($first['id'], $paymentPlan)->json('assessment');
        $third = $this->postReassess($second['id'], $paymentPlan)->json('assessment');
        Assessment::factory()->create();
        Assessment::factory()->create(['student_id' => Assessment::find($first['id'])->student_id]);

        $historyResponse = $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/'.$first['id'].'/history')->assertOk();

        $this->assertSame([$third['id'], $second['id'], $first['id']], array_column($historyResponse->json('assessments'), 'id'));
        $this->assertSame(['current', 'superseded', 'superseded'], array_column($historyResponse->json('assessments'), 'status'));
        $this->assertSame(self::ASSESSMENT_ROW_KEYS, array_keys($historyResponse->json('assessments.0')));
    }

    public function test_history_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::ASSESSMENTS_URL.'/'.self::MISSING_ID.'/history')->assertNotFound();
    }

    public function test_create_failure_leaves_no_partial_rows(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $payload = $this->buildAssessmentPayload($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        AssessmentInstallment::creating(fn () => throw new RuntimeException('forced failure'));

        $this->postAssessment($payload)->assertStatus(500);

        foreach (['assessments', 'assessment_fee_lines', 'assessment_discounts', 'assessment_installments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
