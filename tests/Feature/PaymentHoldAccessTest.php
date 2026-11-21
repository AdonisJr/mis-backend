<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentHolds;
use Tests\TestCase;

class PaymentHoldAccessTest extends TestCase
{
    use InteractsWithPaymentHolds;
    use RefreshDatabase;

    private const OWED = '1500.00';

    private Assessment $assessment;

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideHoldRoutes(): array
    {
        return [
            'holds list' => ['GET', self::HOLDS_URL],
            'hold check' => ['GET', self::HOLDS_URL.'/check'],
            'grant note' => ['POST', self::NOTES_URL],
            'cancel note' => ['PUT', self::NOTES_URL.'/1/cancel'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNoteWriteRoutes(): array
    {
        return ['grant note' => ['grant'], 'cancel note' => ['cancel']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpHoldFixtures();
        $this->assessment = $this->createYearAssessment($this->year2029, self::OWED);
    }

    #[DataProvider('provideHoldRoutes')]
    public function test_guest_gets_401_on_hold_routes(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    #[DataProvider('provideHoldRoutes')]
    public function test_user_without_staff_role_gets_403_on_hold_routes(string $method, string $uri): void
    {
        $roleLessToken = $this->createTokenFor(User::factory()->create());

        $this->sendAs($roleLessToken, $method, $uri)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideNoteWriteRoutes')]
    public function test_registrar_gets_403_on_note_writes(string $write): void
    {
        $existingNote = $this->createNote($this->year2029);
        $before = $this->snapshotNoteTable();
        $registrarToken = $this->createRegistrarToken();

        $response = $write === 'grant'
            ? $this->grantNote($registrarToken, $this->buildNotePayload($this->assessment, ['promise_date' => '2030-07-20']))
            : $this->cancelNote($registrarToken, $existingNote->id);

        $response->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        $this->assertEquals($before, $this->snapshotNoteTable());
    }

    public function test_registrar_can_read_holds_check_and_ledger_notes(): void
    {
        $registrarToken = $this->createRegistrarToken();
        $this->createNote($this->year2029);

        $this->fetchHolds($registrarToken)->assertOk()->assertJsonCount(1, 'payment_holds');
        $this->fetchHoldCheck($registrarToken, ['student_id' => $this->debtor->id, 'school_year_id' => $this->schoolYear->id])->assertOk();
        $this->fetchLedger($registrarToken, $this->assessment->only(['student_id', 'school_year_id']))->assertOk()->assertJsonCount(1, 'ledger.promissory_notes');
    }

    public function test_cashier_can_use_every_hold_endpoint(): void
    {
        $this->assertEveryHoldEndpointWorksFor($this->createCashierToken());
    }

    public function test_admin_can_use_every_hold_endpoint(): void
    {
        $this->assertEveryHoldEndpointWorksFor($this->adminToken);
    }

    private function assertEveryHoldEndpointWorksFor(string $token): void
    {
        $noteId = $this->grantNote($token, $this->buildNotePayload($this->assessment))->assertCreated()->json('promissory_note.id');

        $this->fetchHolds($token)->assertOk()->assertJsonCount(1, 'payment_holds');
        $this->fetchHoldCheck($token, ['student_id' => $this->debtor->id, 'school_year_id' => $this->schoolYear->id])->assertOk();
        $this->fetchLedger($token, $this->assessment->only(['student_id', 'school_year_id']))->assertOk()->assertJsonCount(1, 'ledger.promissory_notes');
        $this->cancelNote($token, $noteId)->assertOk();

        $this->assertNotNull(PromissoryNote::findOrFail($noteId)->cancelled_at);
    }
}
