<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class JournalAccessTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRoutes(): array
    {
        return [
            'list' => ['GET', '/api/mis/journal-entries'],
            'show' => ['GET', '/api/mis/journal-entries/1'],
            'create' => ['POST', '/api/mis/journal-entries'],
            'update' => ['PUT', '/api/mis/journal-entries/1'],
            'delete' => ['DELETE', '/api/mis/journal-entries/1'],
            'post' => ['POST', '/api/mis/journal-entries/1/post'],
            'reverse' => ['POST', '/api/mis/journal-entries/1/reverse'],
            'general ledger' => ['GET', '/api/mis/general-ledger'],
        ];
    }

    #[DataProvider('provideRoutes')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('provideRoutes')]
    public function test_registrar_cashier_and_teacher_get_403(string $method, string $uri): void
    {
        foreach ([$this->createRegistrarToken(), $this->createCashierToken(), $this->createTeacherToken()] as $staffToken) {
            $this->sendAs($staffToken, $method, $uri, $this->buildEntryPayload())->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
        }

        $this->assertSame(0, JournalEntry::count());
    }

    #[DataProvider('provideRoutes')]
    public function test_role_less_user_gets_403(string $method, string $uri): void
    {
        $this->sendAs($this->createRoleLessToken(), $method, $uri, $this->buildEntryPayload())->assertForbidden();

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_admin_uses_every_endpoint(): void
    {
        $this->exerciseEveryEndpoint($this->adminToken);
    }

    public function test_accountant_uses_every_endpoint(): void
    {
        $this->exerciseEveryEndpoint($this->createAccountantToken());
    }

    public function test_request_without_accept_header_gets_json_401(): void
    {
        $this->get(self::JOURNAL_ENTRIES_URL)->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    private function exerciseEveryEndpoint(string $token): void
    {
        $entryUrl = self::JOURNAL_ENTRIES_URL.'/';
        $createdId = $this->sendAs($token, 'POST', self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload())->assertCreated()->json('journal_entry.id');

        $this->sendAs($token, 'GET', self::JOURNAL_ENTRIES_URL)->assertOk()->assertJsonCount(1, 'journal_entries');
        $this->sendAs($token, 'GET', $entryUrl.$createdId)->assertOk();
        $this->sendAs($token, 'PUT', $entryUrl.$createdId, $this->buildEntryPayload(['description' => 'Edited']))->assertOk();
        $this->sendAs($token, 'POST', $entryUrl.$createdId.'/post')->assertOk()->assertJsonPath('journal_entry.number', 'JV-000001');
        $this->sendAs($token, 'POST', $entryUrl.$createdId.'/reverse', ['reason' => 'Test'])->assertCreated();
        $this->sendAs($token, 'GET', self::GENERAL_LEDGER_URL.'?account_id='.$this->findSeededAccount('1010')->id)->assertOk();
        $disposableId = $this->sendAs($token, 'POST', self::JOURNAL_ENTRIES_URL, $this->buildEntryPayload())->json('journal_entry.id');
        $this->sendAs($token, 'DELETE', $entryUrl.$disposableId)->assertNoContent();
    }
}
