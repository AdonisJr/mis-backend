<?php

namespace Tests\Feature\Concerns;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Testing\TestResponse;

trait InteractsWithReports
{
    // InteractsWithJournalEntries collides on createAccountantToken and sendAs, so only the billing posting helpers are used
    use InteractsWithBillingPosting;

    private const REPORTS_URL = '/api/mis/reports';

    private const AS_OF = '2030-07-01';

    private const AGING_ASSESSED_ON = '2030-01-01';

    private const ZERO = '0.00';

    private const AGING_BUCKET_KEYS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    private function fetchReport(string $reportPath, string $query = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::REPORTS_URL.'/'.$reportPath.($query === '' ? '' : '?'.$query));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $lineSpecs  account code, debit, credit
     */
    private function postManualEntry(string $entryDate, array $lineSpecs): int
    {
        $lines = array_map(fn (array $lineSpec) => array_filter([
            'account_id' => $this->findAccountByCode($lineSpec[0])->id,
            'debit' => $lineSpec[1] === self::ZERO ? null : $lineSpec[1],
            'credit' => $lineSpec[2] === self::ZERO ? null : $lineSpec[2],
        ]), $lineSpecs);
        $entryId = $this->withToken($this->adminToken)
            ->postJson(self::JOURNAL_ENTRIES_URL, ['entry_date' => $entryDate, 'description' => 'Report test entry', 'lines' => $lines])
            ->assertCreated()
            ->json('journal_entry.id');
        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId.'/post')->assertOk();

        return $entryId;
    }

    private function reverseManualEntry(int $entryId, string $date): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::JOURNAL_ENTRIES_URL.'/'.$entryId.'/reverse', ['reason' => 'Report test', 'date' => $date])
            ->assertSuccessful();
    }

    /**
     * Scenario S: students Abad (5000.00, paid 1000.00, a 500.00 payment voided) and Bautista (5000.00, re-assessed the same day) plus a 300.00 utilities entry.
     */
    private function seedBillingLedger(): void
    {
        $abad = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_ENROLLED, ['first_name' => 'Ana', 'last_name' => 'Abad']);
        $bautista = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_ENROLLED, ['first_name' => 'Ben', 'last_name' => 'Bautista']);
        $cashPlan = $this->createCashPlan();
        $abadAssessment = $this->assessWithTuition(self::TUITION_AMOUNT, $cashPlan, $abad);

        $this->recordPayment($this->adminToken, $this->buildPaymentPayload($abadAssessment['id']))->assertCreated();
        $voidedPaymentId = $this->recordPayment($this->adminToken, $this->buildPaymentPayload($abadAssessment['id'], ['amount' => '500.00']))->assertCreated()->json('payment.id');
        $this->voidPayment($this->adminToken, $voidedPaymentId)->assertOk();

        $bautistaAssessment = $this->assessStudent($bautista, $cashPlan);
        $this->postReassess($bautistaAssessment['id'], $this->createCashPlan(['name' => 'Cash B']))->assertSuccessful();
        $this->postManualEntry('2030-06-15', [['5030', '300.00', self::ZERO], ['1010', self::ZERO, '300.00']]);
    }

    /**
     * A factory assessment that nothing posted, created early enough to be current on any test as-of date.
     *
     * @param  list<array{0: string, 1: string}>  $installmentSpecs  due date, amount
     */
    private function createAgingAssessment(array $installmentSpecs): Assessment
    {
        $netTotal = array_sum(array_column($installmentSpecs, 1));
        $assessment = Assessment::factory()->create(['school_year_id' => $this->schoolYear->id]);
        $assessment->forceFill(['net_total' => $netTotal, 'gross_total' => $netTotal, 'created_at' => self::AGING_ASSESSED_ON])->save();

        foreach ($installmentSpecs as $installmentIndex => [$dueDate, $amount]) {
            $assessment->installments()->create(['sequence' => $installmentIndex + 1, 'label' => 'Due '.($installmentIndex + 1), 'due_date' => $dueDate, 'amount' => $amount]);
        }

        return $assessment->load('installments');
    }

    private function insertUnbalancedEntry(string $entryDate): void
    {
        $entry = JournalEntry::factory()->posted()->create(['entry_date' => $entryDate]);
        JournalLine::factory()->for($entry, 'entry')->create(['account_id' => $this->findAccountByCode('1010')->id, 'debit' => '100.00', 'credit' => self::ZERO]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyRowsByCode(array $rows): array
    {
        return array_combine(array_map('strval', array_column($rows, 'code')), $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function listRowCodes(array $rows): array
    {
        return array_map('strval', array_column($rows, 'code'));
    }
}
