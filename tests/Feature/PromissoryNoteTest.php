<?php

namespace Tests\Feature;

use App\Http\Controllers\PromissoryNoteController;
use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentHolds;
use Tests\TestCase;

class PromissoryNoteTest extends TestCase
{
    use InteractsWithPaymentHolds;
    use RefreshDatabase;

    private const OWED = '1500.00';

    private const NULLABLE_COLUMNS = ['remarks', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'created_at', 'updated_at'];

    private string $cashierToken;

    private Assessment $assessment;

    /**
     * @return array<string, array{string|null}>
     */
    public static function provideInvalidAmounts(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5.00'],
            'three decimals' => ['10.001'],
            'not numeric' => ['abc'],
            'over the column maximum' => ['1000000000000.00'],
            'missing' => [null],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpHoldFixtures();
        $this->cashierToken = $this->createCashierToken();
        $this->assessment = $this->createYearAssessment($this->year2029, self::OWED);
    }

    public function test_promissory_notes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('promissory_notes', [
            'id', 'student_id', 'school_year_id', 'promised_amount', 'promise_date', 'remarks', 'granted_by',
            'cancelled_at', 'cancelled_by', 'cancel_reason', 'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('promissory_notes', 'status'));
        $this->assertFalse(Schema::hasTable('payment_holds'));

        $nullableByColumn = collect(Schema::getColumns('promissory_notes'))->pluck('nullable', 'name');
        $this->assertCount(12, $nullableByColumn);

        foreach ($nullableByColumn as $columnName => $isNullable) {
            $this->assertSame(in_array($columnName, self::NULLABLE_COLUMNS, true), $isNullable, $columnName);
        }
    }

    public function test_note_foreign_keys_restrict_deletes(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('promissory_notes'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();
        $this->assertEquals([
            'student_id' => ['students', 'restrict'],
            'school_year_id' => ['school_years', 'restrict'],
            'granted_by' => ['users', 'restrict'],
            'cancelled_by' => ['users', 'restrict'],
        ], $foreignKeys);

        $note = PromissoryNote::factory()->cancelled()->create();

        foreach (['students' => $note->student_id, 'school_years' => $note->school_year_id, 'users' => $note->granted_by] as $parentTable => $parentId) {
            $this->assertThrows(fn () => DB::table($parentTable)->where('id', $parentId)->delete(), QueryException::class);
        }
        $this->assertThrows(fn () => DB::table('users')->where('id', $note->cancelled_by)->delete(), QueryException::class);
    }

    public function test_cashier_grants_note(): void
    {
        $cashier = User::where('email', self::CASHIER_EMAIL)->firstOrFail();

        $noteResponse = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['remarks' => 'Parent will pay after harvest']))
            ->assertCreated();
        $note = $noteResponse->json('promissory_note');

        $this->assertSame(self::NOTE_KEYS, array_keys($note));
        $this->assertSame(PromissoryNote::STATUS_ACTIVE, $note['status']);
        $this->assertSame($this->debtor->id, $note['student_id']);
        $this->assertSame($this->year2029->id, $note['school_year_id']);
        $this->assertSame(['id' => $this->debtor->id, 'lrn' => '900000000001', 'first_name' => 'Debbie', 'middle_name' => $this->debtor->middle_name, 'last_name' => 'Tor'], $note['student']);
        $this->assertSame(['id' => $this->year2029->id, 'name' => '2029-2030'], $note['school_year']);
        $this->assertSame('500.00', $note['promised_amount']);
        $this->assertSame(self::PROMISE_DATE, $note['promise_date']);
        $this->assertSame('Parent will pay after harvest', $note['remarks']);
        $this->assertSame(['id' => $cashier->id, 'name' => $cashier->name], $note['granted_by']);
        $this->assertSame(PromissoryNote::findOrFail($note['id'])->created_at->toJSON(), $note['granted_at']);
        $this->assertNull($note['cancelled_by']);
        $this->assertNull($note['cancelled_at']);
        $this->assertNull($note['cancel_reason']);
        $this->assertDatabaseCount('promissory_notes', 1);
    }

    public function test_admin_grants_note(): void
    {
        $this->grantNote($this->adminToken, $this->buildNotePayload($this->assessment))
            ->assertCreated()
            ->assertJsonPath('promissory_note.status', PromissoryNote::STATUS_ACTIVE)
            ->assertJsonPath('promissory_note.remarks', null);
    }

    #[DataProvider('provideInvalidAmounts')]
    public function test_invalid_promised_amount_is_rejected(?string $amount): void
    {
        $payload = $this->buildNotePayload($this->assessment, ['promised_amount' => $amount]);
        if ($amount === null) {
            $payload = array_diff_key($payload, ['promised_amount' => true]);
        }
        $before = $this->snapshotNoteTable();

        $this->grantNote($this->cashierToken, $payload)->assertUnprocessable()->assertJsonValidationErrors(['promised_amount']);

        $this->assertEquals($before, $this->snapshotNoteTable());
    }

    public function test_promised_amount_may_equal_but_not_exceed_balance(): void
    {
        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['promised_amount' => '1500.01']))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => PromissoryNoteController::EXCEEDS_BALANCE_MESSAGE,
                'errors' => ['promised_amount' => [PromissoryNoteController::EXCEEDS_BALANCE_MESSAGE]],
            ]);
        $this->assertDatabaseCount('promissory_notes', 0);

        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['promised_amount' => '1500.00']))
            ->assertCreated()
            ->assertJsonPath('promissory_note.promised_amount', '1500.00');
    }

    public function test_promise_date_today_is_accepted_and_yesterday_rejected(): void
    {
        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['promise_date' => '2030-06-30']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['promise_date']);
        $this->assertDatabaseCount('promissory_notes', 0);

        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['promise_date' => '2030-07-01']))
            ->assertCreated()
            ->assertJsonPath('promissory_note.promise_date', '2030-07-01');
    }

    public function test_promise_date_is_required_and_formatted(): void
    {
        $payload = $this->buildNotePayload($this->assessment);

        foreach ([array_diff_key($payload, ['promise_date' => true]), [...$payload, 'promise_date' => '07/15/2030'], [...$payload, 'promise_date' => 'abc']] as $badPayload) {
            $this->grantNote($this->cashierToken, $badPayload)->assertUnprocessable()->assertJsonValidationErrors(['promise_date']);
        }

        $this->assertDatabaseCount('promissory_notes', 0);
    }

    public function test_remarks_are_optional_and_limited_to_500(): void
    {
        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['remarks' => str_repeat('r', 501)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);
        $this->assertDatabaseCount('promissory_notes', 0);

        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['remarks' => str_repeat('r', 500)]))->assertCreated();
    }

    public function test_note_requires_current_assessment_with_balance(): void
    {
        $bareYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2027);
        Assessment::factory()->superseded()->create(['student_id' => $this->debtor->id, 'school_year_id' => $this->schoolYear->id, 'net_total' => self::OWED]);
        $paidAssessment = $this->createYearAssessment($this->year2028, '300.00');
        $this->payAssessment($paidAssessment, '300.00');
        $badPayloads = [
            'no assessment' => $this->buildNotePayload($this->assessment, ['school_year_id' => $bareYear->id]),
            'superseded only' => $this->buildNotePayload($this->assessment, ['school_year_id' => $this->schoolYear->id]),
            'fully paid' => $this->buildNotePayload($paidAssessment, ['promised_amount' => '10.00']),
        ];
        $before = $this->snapshotNoteTable();

        foreach ($badPayloads as $caseName => $badPayload) {
            $this->grantNote($this->cashierToken, $badPayload)
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => PromissoryNoteController::NO_BALANCE_MESSAGE,
                    'errors' => ['school_year_id' => [PromissoryNoteController::NO_BALANCE_MESSAGE]],
                ], $caseName);
        }

        $this->assertEquals($before, $this->snapshotNoteTable());
    }

    public function test_one_active_note_per_student_and_year(): void
    {
        $firstNoteId = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');
        $before = $this->snapshotNoteTable();

        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => PromissoryNoteController::ACTIVE_NOTE_EXISTS_MESSAGE,
                'errors' => ['school_year_id' => [PromissoryNoteController::ACTIVE_NOTE_EXISTS_MESSAGE]],
            ]);
        $this->assertEquals($before, $this->snapshotNoteTable());

        $this->cancelNote($this->cashierToken, $firstNoteId)->assertOk();
        $secondNoteId = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');

        $this->travelTo(Carbon::parse('2030-07-16 00:00:00', self::MANILA));
        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['promise_date']);
        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment, ['promise_date' => '2030-07-20']))->assertCreated();

        $this->assertNotSame($firstNoteId, $secondNoteId);
        $this->assertDatabaseCount('promissory_notes', 3);
    }

    public function test_note_for_other_year_is_independent(): void
    {
        $otherAssessment = $this->createYearAssessment($this->year2028, '250.00');

        $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated();
        $this->grantNote($this->cashierToken, $this->buildNotePayload($otherAssessment, ['promised_amount' => '250.00']))->assertCreated();

        $this->assertDatabaseCount('promissory_notes', 2);
    }

    public function test_cashier_cancels_note(): void
    {
        $cashier = User::where('email', self::CASHIER_EMAIL)->firstOrFail();
        $noteId = $this->grantNote($this->adminToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');

        $cancelResponse = $this->cancelNote($this->cashierToken, $noteId)->assertOk();
        $note = $cancelResponse->json('promissory_note');

        $this->assertSame(self::NOTE_KEYS, array_keys($note));
        $this->assertSame(PromissoryNote::STATUS_CANCELLED, $note['status']);
        $this->assertSame(['id' => $cashier->id, 'name' => $cashier->name], $note['cancelled_by']);
        $this->assertSame(self::CANCEL_REASON, $note['cancel_reason']);
        $this->assertSame(PromissoryNote::findOrFail($noteId)->cancelled_at->toJSON(), $note['cancelled_at']);
        $this->assertDatabaseCount('promissory_notes', 1);
        $this->assertDatabaseHas('promissory_notes', ['id' => $noteId, 'cancelled_by' => $cashier->id, 'cancel_reason' => self::CANCEL_REASON]);
    }

    public function test_cancel_requires_reason_up_to_500(): void
    {
        $noteId = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');
        $before = $this->snapshotNoteTable();

        $this->sendAs($this->cashierToken, 'PUT', self::NOTES_URL.'/'.$noteId.'/cancel')->assertUnprocessable()->assertJsonValidationErrors(['cancel_reason']);
        $this->cancelNote($this->cashierToken, $noteId, str_repeat('c', 501))->assertUnprocessable()->assertJsonValidationErrors(['cancel_reason']);
        $this->assertEquals($before, $this->snapshotNoteTable());

        $this->cancelNote($this->cashierToken, $noteId, str_repeat('c', 500))->assertOk();
    }

    public function test_cancelled_note_cannot_be_cancelled_again(): void
    {
        $noteId = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');
        $this->cancelNote($this->cashierToken, $noteId)->assertOk();
        $before = $this->snapshotNoteTable();

        $this->cancelNote($this->adminToken, $noteId, 'Second tab')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => PromissoryNoteController::ALREADY_CANCELLED_MESSAGE,
                'errors' => ['promissory_note' => [PromissoryNoteController::ALREADY_CANCELLED_MESSAGE]],
            ]);

        $this->assertEquals($before, $this->snapshotNoteTable());
    }

    public function test_cancel_missing_note_returns_404(): void
    {
        $this->cancelNote($this->cashierToken, self::MISSING_ID)->assertNotFound();
    }

    public function test_note_has_no_update_or_delete_route(): void
    {
        $noteId = $this->grantNote($this->cashierToken, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');
        $before = $this->snapshotNoteTable();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertContains(
                $this->sendAs($this->adminToken, $method, self::NOTES_URL.'/'.$noteId, ['promised_amount' => '1.00'])->getStatusCode(),
                [404, 405],
                $method,
            );
        }

        $this->assertEquals($before, $this->snapshotNoteTable());
    }

    public function test_status_precedence_is_cancelled_settled_active_broken(): void
    {
        $studentYear = ['student_id' => $this->debtor->id, 'school_year_id' => $this->year2029->id];
        PromissoryNote::factory()->cancelled()->create([...$studentYear, 'promise_date' => '2030-07-15']);
        $this->assertSame(['cancelled'], $this->fetchNoteStatuses($studentYear));

        PromissoryNote::factory()->create([...$studentYear, 'promise_date' => '2030-07-15']);
        $this->assertSame(['active', 'cancelled'], $this->fetchNoteStatuses($studentYear));

        PromissoryNote::factory()->create([...$studentYear, 'promise_date' => '2030-06-30']);
        $this->assertSame(['broken', 'active', 'cancelled'], $this->fetchNoteStatuses($studentYear));

        $payment = $this->payAssessment($this->assessment, self::OWED);
        $this->assertSame(['settled', 'settled', 'cancelled'], $this->fetchNoteStatuses($studentYear));

        $this->voidPayment($this->adminToken, $payment->id)->assertOk();
        $this->assertSame(['broken', 'active', 'cancelled'], $this->fetchNoteStatuses($studentYear));
    }

    /**
     * @param  array<string, int>  $studentYear
     * @return list<string>
     */
    private function fetchNoteStatuses(array $studentYear): array
    {
        $notes = $this->fetchLedger($this->adminToken, $studentYear)->assertOk()->json('ledger.promissory_notes');

        return array_column($notes, 'status');
    }
}
