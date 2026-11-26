<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\DiscountType;
use App\Models\FeeItem;
use App\Models\GradeLevel;
use App\Models\JournalEntry;
use App\Models\PaymentPlan;
use App\Models\PostingMapping;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Support\BillingPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class ReassessPostingTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private Student $student;

    private PaymentPlan $paymentPlan;

    private DiscountType $discountType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT]]);
        $this->student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->paymentPlan = $this->createCashPlan();
        $this->discountType = $this->createType(['name' => self::SIBLING_TYPE, 'value' => '500.00']);
    }

    public function test_reassess_reverses_the_old_entry_and_posts_the_new_one(): void
    {
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);
        $replacementId = $this->postReassess($first->id, $this->createCashPlan(['name' => 'Cash again']))->assertSuccessful()->json('assessment.id');

        $original = $this->findOriginalEntry($first);
        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

        $this->assertSame($reversal->id, $original->reversed_by_id);
        $this->assertSame([BillingPosting::REASSESSED_REASON, JournalEntry::SOURCE_ASSESSMENT, $first->id], [$reversal->reversal_reason, $reversal->source_type, $reversal->source_id]);
        $this->assertNotNull($this->findOriginalEntry(Assessment::findOrFail($replacementId)));
        $this->assertSame(3, JournalEntry::count());
    }

    public function test_reversal_and_repost_share_one_transaction(): void
    {
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);
        // armed after the original post: only the replacement's entry throws, after the reversal already posted
        JournalEntry::creating(function (JournalEntry $entry) {
            if ($entry->reversal_of_id === null && $entry->source_type === JournalEntry::SOURCE_ASSESSMENT) {
                throw new RuntimeException('forced failure');
            }
        });

        $this->postReassess($first->id, $this->createCashPlan(['name' => 'Cash again']))->assertStatus(500);

        $this->assertSame(Assessment::STATUS_CURRENT, $first->refresh()->status);
        $this->assertNull($this->findOriginalEntry($first)->reversed_by_id);
        $this->assertSame(1, Assessment::count());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_reassess_of_a_never_posted_assessment_only_posts_the_new_one(): void
    {
        $this->unmapPurpose('revenue_tuition');
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);
        PostingMapping::create(['purpose' => PostingMapping::PURPOSE_REVENUE_TUITION, 'account_id' => $this->findAccountByCode('4010')->id]);

        $replacementId = $this->postReassess($first->id, $this->paymentPlan)->assertSuccessful()->json('assessment.id');

        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(0, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->assertNotNull($this->findOriginalEntry(Assessment::findOrFail($replacementId)));
        $this->assertNull($this->findOriginalEntry($first));
    }

    public function test_adding_a_discount_with_reassess_reverses_and_reposts(): void
    {
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);

        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->discountType), 'reassess' => true])->assertCreated();

        $replacement = Assessment::current()->firstOrFail();
        $this->assertSame(3, JournalEntry::count());
        $this->assertNotNull($this->findOriginalEntry($first)->reversed_by_id);
        $this->assertSame(['1100' => ['4500.00', '0.00'], '4090' => ['500.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($this->findOriginalEntry($replacement)));
    }

    public function test_removing_a_discount_with_reassess_reverses_and_reposts(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);

        $this->deleteAssignment($studentDiscount)->assertNoContent();

        $replacement = Assessment::current()->firstOrFail();
        $this->assertSame(3, JournalEntry::count());
        $this->assertNotNull($this->findOriginalEntry($first)->reversed_by_id);
        $this->assertSame(['1100' => ['5000.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($this->findOriginalEntry($replacement)));
    }

    public function test_reversal_is_dated_the_reassess_day(): void
    {
        $first = Assessment::findOrFail($this->assessStudent($this->student, $this->paymentPlan)['id']);
        Carbon::setTestNow('2030-07-10');

        $this->postReassess($first->id, $this->createCashPlan(['name' => 'Cash again']))->assertSuccessful();

        $reversal = JournalEntry::whereNotNull('reversal_of_id')->firstOrFail();
        $this->assertSame('2030-07-10', $reversal->entry_date->toDateString());
        $this->assertSame(self::ASSESSED_ON, $this->findOriginalEntry($first)->entry_date->toDateString());
        $this->assertSame('2030-07-10', $this->findOriginalEntry(Assessment::current()->firstOrFail())->entry_date->toDateString());
    }

    private function deleteAssignment(StudentDiscount $studentDiscount): TestResponse
    {
        return $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.$studentDiscount->id.'?reassess=1');
    }
}
