<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountController;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithJournalEntries;
use Tests\TestCase;

class AccountUsageTest extends TestCase
{
    use InteractsWithJournalEntries;
    use RefreshDatabase;

    private const SEED_MIGRATION = '2026_11_26_100001_seed_starter_chart_of_accounts.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->travelTo(self::TEST_NOW);
    }

    public function test_account_with_a_draft_line_is_used(): void
    {
        $account = Account::factory()->create();
        $this->assertFalse($account->isUsed());

        JournalLine::factory()->for(JournalEntry::factory(), 'entry')->for($account)->create();

        $this->assertTrue($account->isUsed());
    }

    public function test_account_with_a_posted_line_is_used(): void
    {
        $this->createPostedEntry('5040', '1010', '10.00');

        $this->assertTrue($this->findSeededAccount('5040')->isUsed());
        $this->assertFalse($this->findSeededAccount('5030')->isUsed());
    }

    public function test_used_account_code_cannot_change(): void
    {
        $account = $this->createUsedAccount();

        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.$account->id, $this->buildAccountPayload(['code' => '1998']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', UpdateAccountRequest::CODE_LOCKED_MESSAGE);
    }

    public function test_used_account_type_cannot_change(): void
    {
        $account = $this->createUsedAccount();
        $payload = $this->buildAccountPayload(['type' => Account::TYPE_EXPENSE, 'subtype' => Account::SUBTYPE_OPERATING_EXPENSE]);

        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.$account->id, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.type.0', UpdateAccountRequest::TYPE_LOCKED_MESSAGE);
    }

    public function test_used_account_delete_is_refused(): void
    {
        $account = $this->createUsedAccount();

        $this->withToken($this->adminToken)->deleteJson(self::ACCOUNTS_URL.'/'.$account->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', AccountController::USED_DELETE_MESSAGE);
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_name_change_and_deactivation_still_work_on_a_used_account(): void
    {
        $account = $this->createUsedAccount();

        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.$account->id, $this->buildAccountPayload(['name' => 'Renamed Fund']))
            ->assertOk()
            ->assertJsonPath('account.name', 'Renamed Fund');
        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.$account->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('account.is_active', false);
    }

    public function test_is_used_flag_is_exposed_on_list_and_show(): void
    {
        $usedAccount = $this->createUsedAccount();

        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'/'.$usedAccount->id)->assertOk()->assertJsonPath('account.is_used', true);
        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'/'.$this->findSeededAccount('1020')->id)->assertOk()->assertJsonPath('account.is_used', false);

        $accountRows = collect($this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'?search=Petty')->assertOk()->json('accounts'));
        $this->assertTrue($accountRows->firstWhere('id', $usedAccount->id)['is_used']);
        $listedRows = $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL)->assertOk()->json('accounts');
        $this->assertSame([false], array_values(array_unique(array_column(array_filter($listedRows, fn (array $row) => $row['id'] !== $usedAccount->id), 'is_used'))));
    }

    public function test_account_list_reads_is_used_without_per_row_queries(): void
    {
        $this->createUsedAccount();

        DB::enableQueryLog();
        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'?search=Petty')->assertOk()->assertJsonCount(1, 'accounts');
        $singleRowCount = $this->countLoggedQueries();

        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL)->assertOk();

        $this->assertSame($singleRowCount, $this->countLoggedQueries());
    }

    public function test_is_used_tolerates_a_missing_journal_lines_table(): void
    {
        $account = $this->findSeededAccount('1010');
        Schema::drop('journal_lines');

        $this->assertFalse($account->isUsed());
    }

    public function test_seed_rollback_keeps_a_used_seeded_account(): void
    {
        $this->createPostedEntry('5040', '1010', '10.00');
        $seedMigration = require database_path('migrations/'.self::SEED_MIGRATION);

        $seedMigration->down();

        $this->assertDatabaseHas('accounts', ['code' => '1010']);
        $this->assertDatabaseMissing('accounts', ['code' => '1020']);
    }

    private function createUsedAccount(): Account
    {
        $account = Account::create($this->buildAccountPayload());
        JournalLine::factory()->for(JournalEntry::factory(), 'entry')->for($account)->create();

        return $account;
    }
}
