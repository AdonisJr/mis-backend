<?php

namespace Tests\Feature\Concerns;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithPaymentHolds
{
    use InteractsWithPayments;

    private const NOTES_URL = '/api/mis/promissory-notes';

    private const HOLDS_URL = '/api/mis/payment-holds';

    private const NOTE_KEYS = [
        'id', 'student_id', 'school_year_id', 'student', 'school_year', 'promised_amount', 'promise_date', 'remarks', 'status',
        'granted_by', 'granted_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    private const HOLD_KEYS = ['student_id', 'school_year_id', 'on_hold', 'unpaid_years'];

    private const UNPAID_YEAR_KEYS = ['school_year', 'net_total', 'total_paid', 'balance', 'active_note'];

    private const HOLD_ROW_KEYS = ['student_id', 'school_year_id', 'student', 'school_year', 'balance', 'note_status', 'note'];

    private const SCHOOL_YEAR_2028 = ['name' => '2028-2029', 'start_date' => '2028-06-01', 'end_date' => '2029-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2029 = ['name' => '2029-2030', 'start_date' => '2029-06-01', 'end_date' => '2030-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2027 = ['name' => '2027-2028', 'start_date' => '2027-06-01', 'end_date' => '2028-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const PROMISE_DATE = '2030-07-15';

    private const CANCEL_REASON = 'Parent asked to pay in cash';

    private Student $debtor;

    private SchoolYear $year2028;

    private SchoolYear $year2029;

    private function setUpHoldFixtures(): void
    {
        $this->setUpAssessmentFixtures();
        $this->debtor = Student::factory()->create(['lrn' => '900000000001', 'first_name' => 'Debbie', 'last_name' => 'Tor']);
        $this->year2028 = SchoolYear::factory()->create(self::SCHOOL_YEAR_2028);
        $this->year2029 = SchoolYear::factory()->create(self::SCHOOL_YEAR_2029);
    }

    private function createYearAssessment(SchoolYear $schoolYear, string $netTotal, ?Student $student = null): Assessment
    {
        return Assessment::factory()->create([
            'student_id' => ($student ?? $this->debtor)->id,
            'school_year_id' => $schoolYear->id,
            'net_total' => $netTotal,
        ]);
    }

    private function payAssessment(Assessment $assessment, string $amount): Payment
    {
        return Payment::factory()->create(['assessment_id' => $assessment->id, 'amount' => $amount]);
    }

    /**
     * @param  array<string, mixed>  $noteFields
     */
    private function createNote(SchoolYear $schoolYear, array $noteFields = [], ?Student $student = null): PromissoryNote
    {
        return PromissoryNote::factory()->create([
            'student_id' => ($student ?? $this->debtor)->id,
            'school_year_id' => $schoolYear->id,
            'promise_date' => self::PROMISE_DATE,
            ...$noteFields,
        ]);
    }

    /**
     * @param  array<string, mixed>  $noteFields
     * @return array<string, mixed>
     */
    private function buildNotePayload(Assessment $assessment, array $noteFields = []): array
    {
        return [
            'student_id' => $assessment->student_id,
            'school_year_id' => $assessment->school_year_id,
            'promised_amount' => '500.00',
            'promise_date' => self::PROMISE_DATE,
            ...$noteFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function grantNote(string $token, array $payload): TestResponse
    {
        return $this->sendAs($token, 'POST', self::NOTES_URL, $payload);
    }

    private function cancelNote(string $token, int $noteId, string $reason = self::CANCEL_REASON): TestResponse
    {
        return $this->sendAs($token, 'PUT', self::NOTES_URL.'/'.$noteId.'/cancel', ['cancel_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchHoldCheck(string $token, array $query): TestResponse
    {
        return $this->sendAs($token, 'GET', self::HOLDS_URL.'/check?'.http_build_query($query));
    }

    private function checkDebtorHold(?SchoolYear $targetYear = null): TestResponse
    {
        return $this->fetchHoldCheck($this->adminToken, ['student_id' => $this->debtor->id, 'school_year_id' => ($targetYear ?? $this->schoolYear)->id]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchHolds(string $token, array $query = []): TestResponse
    {
        return $this->sendAs($token, 'GET', self::HOLDS_URL.'?'.http_build_query($query));
    }

    /**
     * @return list<array<int, object>>
     */
    private function snapshotNoteTable(): array
    {
        return [DB::table('promissory_notes')->orderBy('id')->get()->all()];
    }
}
