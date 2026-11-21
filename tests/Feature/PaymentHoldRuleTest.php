<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPaymentHolds;
use Tests\TestCase;

class PaymentHoldRuleTest extends TestCase
{
    use InteractsWithPaymentHolds;
    use RefreshDatabase;

    private const OWED = '1500.00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpHoldFixtures();
    }

    public function test_hold_check_reports_unpaid_earlier_year(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->payAssessment($this->createYearAssessment($this->year2028, '800.00'), '800.00');

        $holdResponse = $this->checkDebtorHold()->assertOk();
        $hold = $holdResponse->json('hold');

        $this->assertSame(self::HOLD_KEYS, array_keys($hold));
        $this->assertSame($this->debtor->id, $hold['student_id']);
        $this->assertSame($this->schoolYear->id, $hold['school_year_id']);
        $this->assertTrue($hold['on_hold']);
        $this->assertCount(1, $hold['unpaid_years']);
        $this->assertSame(self::UNPAID_YEAR_KEYS, array_keys($hold['unpaid_years'][0]));
        $this->assertSame(['id' => $this->year2029->id, 'name' => '2029-2030'], $hold['unpaid_years'][0]['school_year']);
        $this->assertSame(self::OWED, $hold['unpaid_years'][0]['net_total']);
        $this->assertSame('0.00', $hold['unpaid_years'][0]['total_paid']);
        $this->assertSame(self::OWED, $hold['unpaid_years'][0]['balance']);
        $this->assertNull($hold['unpaid_years'][0]['active_note']);
    }

    public function test_fully_paid_earlier_year_does_not_hold(): void
    {
        $this->payAssessment($this->createYearAssessment($this->year2029, self::OWED), self::OWED);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', false)
            ->assertJsonPath('hold.unpaid_years', []);
    }

    public function test_voided_payment_restores_hold(): void
    {
        $assessment = $this->createYearAssessment($this->year2029, self::OWED);
        Payment::factory()->voided()->create(['assessment_id' => $assessment->id, 'amount' => self::OWED]);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', true)
            ->assertJsonPath('hold.unpaid_years.0.balance', self::OWED)
            ->assertJsonPath('hold.unpaid_years.0.total_paid', '0.00');
    }

    public function test_superseded_assessment_is_ignored(): void
    {
        Assessment::factory()->superseded()->create(['student_id' => $this->debtor->id, 'school_year_id' => $this->year2029->id, 'net_total' => self::OWED]);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', false)
            ->assertJsonPath('hold.unpaid_years', []);
    }

    public function test_same_and_later_years_are_ignored(): void
    {
        $laterYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createYearAssessment($this->schoolYear, self::OWED);
        $this->createYearAssessment($laterYear, self::OWED);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', false)
            ->assertJsonPath('hold.unpaid_years', []);
    }

    public function test_multiple_unpaid_years_are_listed_oldest_first(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createYearAssessment($this->year2028, '250.00');

        $unpaidYears = $this->checkDebtorHold()->assertOk()->json('hold.unpaid_years');

        $this->assertSame(['2028-2029', '2029-2030'], array_column(array_column($unpaidYears, 'school_year'), 'name'));
        $this->assertSame(['250.00', self::OWED], array_column($unpaidYears, 'balance'));
    }

    public function test_active_note_covers_balance_regardless_of_amount(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $note = $this->createNote($this->year2029, ['promised_amount' => '1.00']);

        $holdResponse = $this->checkDebtorHold()->assertOk()->assertJsonPath('hold.on_hold', false);

        $this->assertSame(
            ['id' => $note->id, 'promise_date' => self::PROMISE_DATE, 'promised_amount' => '1.00'],
            $holdResponse->json('hold.unpaid_years.0.active_note'),
        );
    }

    public function test_note_covers_through_promise_date_and_expires_next_manila_day(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createNote($this->year2029, ['promise_date' => '2030-07-10']);

        $this->travelTo(Carbon::parse('2030-07-10 23:59:59', self::MANILA));
        $this->checkDebtorHold()->assertOk()->assertJsonPath('hold.on_hold', false);

        $this->travelTo(Carbon::parse('2030-07-11 00:00:00', self::MANILA));
        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', true)
            ->assertJsonPath('hold.unpaid_years.0.active_note', null);
    }

    public function test_cancelled_note_does_not_cover(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        PromissoryNote::factory()->cancelled()->create([
            'student_id' => $this->debtor->id,
            'school_year_id' => $this->year2029->id,
            'promise_date' => self::PROMISE_DATE,
        ]);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', true)
            ->assertJsonPath('hold.unpaid_years.0.active_note', null);
    }

    public function test_note_covers_only_its_own_year(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createNote($this->year2028);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', true)
            ->assertJsonPath('hold.unpaid_years.0.active_note', null);
    }

    public function test_hold_check_rejects_invalid_ids(): void
    {
        $validQuery = ['student_id' => $this->debtor->id, 'school_year_id' => $this->schoolYear->id];

        foreach (['student_id', 'school_year_id'] as $field) {
            foreach ([null, 'abc', self::MISSING_ID] as $badValue) {
                $query = $badValue === null ? array_diff_key($validQuery, [$field => true]) : [...$validQuery, $field => $badValue];

                $this->fetchHoldCheck($this->adminToken, $query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
            }
        }
    }

    public function test_hold_check_query_count_is_independent_of_years(): void
    {
        $this->payAssessment($this->createYearAssessment($this->year2029, self::OWED), '100.00');
        DB::enableQueryLog();
        $this->checkDebtorHold()->assertOk()->assertJsonCount(1, 'hold.unpaid_years');
        $oneYearCount = $this->countQueriesWithoutTokens();

        $this->payAssessment($this->createYearAssessment($this->year2028, '700.00'), '100.00');
        $this->payAssessment($this->createYearAssessment(SchoolYear::factory()->create(self::SCHOOL_YEAR_2027), '300.00'), '100.00');
        DB::flushQueryLog();
        $this->checkDebtorHold()->assertOk()->assertJsonCount(3, 'hold.unpaid_years');

        $this->assertSame($oneYearCount, $this->countQueriesWithoutTokens());
    }

    public function test_hold_check_not_on_hold_when_already_enrolled_in_target_year(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->enrollStudent($this->debtor, GradeLevel::STAGE_JHS, Enrollment::STATUS_DROPPED);

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.on_hold', false)
            ->assertJsonCount(1, 'hold.unpaid_years')
            ->assertJsonPath('hold.unpaid_years.0.balance', self::OWED);
    }

    public function test_balances_are_exact_decimals(): void
    {
        $assessment = $this->createYearAssessment($this->year2029, '1000.30');
        $this->payAssessment($assessment, '0.10');
        $this->payAssessment($assessment, '0.20');

        $this->checkDebtorHold()->assertOk()
            ->assertJsonPath('hold.unpaid_years.0.total_paid', '0.30')
            ->assertJsonPath('hold.unpaid_years.0.balance', '1000.00');
    }
}
