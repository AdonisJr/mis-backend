<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentHolds;
use Tests\TestCase;

class PaymentHoldListTest extends TestCase
{
    use InteractsWithPaymentHolds;
    use RefreshDatabase;

    private const OWED = '1500.00';

    private const PAGE_SIZE = 15;

    private string $cashierToken;

    /**
     * @return array<string, array{string}>
     */
    public static function provideNoteStatuses(): array
    {
        return [
            'none' => [PromissoryNote::STATUS_NONE],
            'active' => [PromissoryNote::STATUS_ACTIVE],
            'broken' => [PromissoryNote::STATUS_BROKEN],
            'settled' => [PromissoryNote::STATUS_SETTLED],
            'cancelled' => [PromissoryNote::STATUS_CANCELLED],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpHoldFixtures();
        $this->cashierToken = $this->createCashierToken();
    }

    public function test_list_returns_unpaid_and_noted_years(): void
    {
        $this->createYearAssessment($this->schoolYear, '100.00');
        $this->createYearAssessment($this->year2029, self::OWED);
        $paidAssessment = $this->createYearAssessment($this->year2028, '300.00');
        $this->payAssessment($paidAssessment, '300.00');
        $settledNote = $this->createNote($this->year2028);
        $otherStudent = Student::factory()->create();
        $this->payAssessment($this->createYearAssessment($this->year2029, '200.00', $otherStudent), '200.00');

        $holdsResponse = $this->fetchHolds($this->cashierToken)->assertOk();
        $paymentHolds = $holdsResponse->json('payment_holds');

        $this->assertSame(self::HOLD_ROW_KEYS, array_keys($paymentHolds[0]));
        $this->assertSame(['2030-2031', '2029-2030', '2028-2029'], array_column(array_column($paymentHolds, 'school_year'), 'name'));
        $this->assertSame(['100.00', self::OWED, '0.00'], array_column($paymentHolds, 'balance'));
        $this->assertSame([PromissoryNote::STATUS_NONE, PromissoryNote::STATUS_NONE, PromissoryNote::STATUS_SETTLED], array_column($paymentHolds, 'note_status'));
        $this->assertSame([null, null], array_slice(array_column($paymentHolds, 'note'), 0, 2));
        $this->assertSame($settledNote->id, $paymentHolds[2]['note']['id']);
        $this->assertSame(['id', 'promise_date', 'promised_amount', 'granted_by', 'granted_at'], array_keys($paymentHolds[2]['note']));
        $this->assertSame([$this->debtor->id], array_values(array_unique(array_column($paymentHolds, 'student_id'))));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 3], $holdsResponse->json('meta'));
    }

    public function test_row_shows_newest_note_of_the_year(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createNote($this->year2029, ['promised_amount' => '100.00']);
        $newestNote = $this->createNote($this->year2029, ['promised_amount' => '200.00']);

        $holdsResponse = $this->fetchHolds($this->cashierToken)->assertOk()->assertJsonCount(1, 'payment_holds');

        $this->assertSame($newestNote->id, $holdsResponse->json('payment_holds.0.note.id'));
        $this->assertSame('200.00', $holdsResponse->json('payment_holds.0.note.promised_amount'));
        $this->assertSame(PromissoryNote::STATUS_ACTIVE, $holdsResponse->json('payment_holds.0.note_status'));
    }

    #[DataProvider('provideNoteStatuses')]
    public function test_list_filters_by_note_status(string $status): void
    {
        $studentsByStatus = $this->createStudentsForEveryStatus();

        $holdsResponse = $this->fetchHolds($this->cashierToken, ['status' => $status])->assertOk()->assertJsonCount(1, 'payment_holds');

        $this->assertSame($studentsByStatus[$status]->id, $holdsResponse->json('payment_holds.0.student_id'));
        $this->assertSame($status, $holdsResponse->json('payment_holds.0.note_status'));
        $this->assertSame(1, $holdsResponse->json('meta.total'));
        $this->assertCount(5, $this->fetchHolds($this->cashierToken)->json('payment_holds'));
    }

    public function test_list_filters_by_school_year(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createYearAssessment($this->year2028, '250.00');

        $holdsResponse = $this->fetchHolds($this->cashierToken, ['school_year_id' => $this->year2028->id])->assertOk()->assertJsonCount(1, 'payment_holds');

        $this->assertSame($this->year2028->id, $holdsResponse->json('payment_holds.0.school_year_id'));
        $this->assertSame('250.00', $holdsResponse->json('payment_holds.0.balance'));
    }

    public function test_list_searches_student_name_and_lrn(): void
    {
        $otherStudent = Student::factory()->create(['lrn' => '900000000002', 'first_name' => 'Ana', 'last_name' => 'Lopez']);
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createYearAssessment($this->year2029, '50.00', $otherStudent);

        foreach (['debbie', 'TOR', '900000000001', 'debbie tor'] as $debtorSearch) {
            $this->fetchHolds($this->cashierToken, ['search' => $debtorSearch])->assertOk()
                ->assertJsonCount(1, 'payment_holds')
                ->assertJsonPath('payment_holds.0.student_id', $this->debtor->id);
        }

        $this->fetchHolds($this->cashierToken, ['search' => 'lopez'])->assertOk()->assertJsonPath('payment_holds.0.student_id', $otherStudent->id);
        $this->fetchHolds($this->cashierToken, ['search' => 'nobody'])->assertOk()->assertJsonCount(0, 'payment_holds')->assertJsonPath('meta.total', 0);
    }

    public function test_list_paginates_fifteen_per_page_in_name_order(): void
    {
        $lastNames = [];
        foreach (range(16, 1) as $number) {
            $lastName = sprintf('Zed%02d', $number);
            $lastNames[] = $lastName;
            $this->createYearAssessment($this->year2029, '10.00', Student::factory()->create(['last_name' => $lastName]));
        }
        sort($lastNames);

        $firstPage = $this->fetchHolds($this->cashierToken)->assertOk();
        $secondPage = $this->fetchHolds($this->cashierToken, ['page' => 2])->assertOk();

        $this->assertSame(array_slice($lastNames, 0, self::PAGE_SIZE), array_column(array_column($firstPage->json('payment_holds'), 'student'), 'last_name'));
        $this->assertSame(array_slice($lastNames, self::PAGE_SIZE), array_column(array_column($secondPage->json('payment_holds'), 'student'), 'last_name'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16], $firstPage->json('meta'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16], $secondPage->json('meta'));
    }

    public function test_list_rejects_invalid_filters(): void
    {
        foreach ([['status', 'bogus'], ['school_year_id', self::MISSING_ID], ['page', 0]] as [$field, $badValue]) {
            $this->fetchHolds($this->cashierToken, [$field => $badValue])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $this->createUnpaidRowWithNote($this->debtor, $this->year2029);
        DB::enableQueryLog();
        $this->fetchHolds($this->cashierToken)->assertOk()->assertJsonCount(1, 'payment_holds');
        $oneRowCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 15) as $unusedIndex) {
            $this->createUnpaidRowWithNote(Student::factory()->create(), $this->year2029);
        }
        DB::flushQueryLog();
        $this->fetchHolds($this->cashierToken)->assertOk()->assertJsonPath('meta.total', 16);

        $this->assertSame($oneRowCount, $this->countQueriesWithoutTokens());
    }

    private function createUnpaidRowWithNote(Student $student, SchoolYear $schoolYear): void
    {
        $this->payAssessment($this->createYearAssessment($schoolYear, self::OWED, $student), '100.00');
        $this->createNote($schoolYear, [], $student);
    }

    /**
     * One student per note status, all in 2029-2030.
     *
     * @return array<string, Student>
     */
    private function createStudentsForEveryStatus(): array
    {
        $studentsByStatus = [];
        foreach (self::provideNoteStatuses() as [$status]) {
            $studentsByStatus[$status] = Student::factory()->create();
            $assessment = $this->createYearAssessment($this->year2029, self::OWED, $studentsByStatus[$status]);
            $noteFields = ['promised_amount' => '100.00'];

            match ($status) {
                PromissoryNote::STATUS_ACTIVE => $this->createNote($this->year2029, $noteFields, $studentsByStatus[$status]),
                PromissoryNote::STATUS_BROKEN => $this->createNote($this->year2029, [...$noteFields, 'promise_date' => '2030-06-30'], $studentsByStatus[$status]),
                PromissoryNote::STATUS_SETTLED => $this->settleWithNote($assessment, $studentsByStatus[$status]),
                PromissoryNote::STATUS_CANCELLED => PromissoryNote::factory()->cancelled()->create(['student_id' => $studentsByStatus[$status]->id, 'school_year_id' => $this->year2029->id]),
                default => null,
            };
        }

        return $studentsByStatus;
    }

    private function settleWithNote(Assessment $assessment, Student $student): void
    {
        $this->payAssessment($assessment, self::OWED);
        $this->createNote($this->year2029, [], $student);
    }
}
