<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithAccounts;
use Tests\TestCase;

class AccountCrudTest extends TestCase
{
    use InteractsWithAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_accounts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('accounts', ['code', 'name', 'type', 'subtype', 'is_contra', 'description', 'is_active']));

        $accountColumns = collect(Schema::getColumns('accounts'));
        $this->assertTrue($accountColumns->firstWhere('name', 'description')['nullable']);
        $this->assertFalse($accountColumns->firstWhere('name', 'code')['nullable']);
        $this->assertFalse($accountColumns->firstWhere('name', 'subtype')['nullable']);

        $codeIndex = collect(Schema::getIndexes('accounts'))->first(fn (array $index) => $index['columns'] === ['code']);
        $this->assertNotNull($codeIndex);
        $this->assertTrue($codeIndex['unique']);
    }

    public function test_admin_creates_account(): void
    {
        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload(['description' => 'Small cash drawer']))
            ->assertCreated()
            ->assertJsonPath('account.code', self::NEW_ACCOUNT_CODE)
            ->assertJsonPath('account.name', self::NEW_ACCOUNT_NAME)
            ->assertJsonPath('account.type', Account::TYPE_ASSET)
            ->assertJsonPath('account.subtype', Account::SUBTYPE_CURRENT_ASSET)
            ->assertJsonPath('account.is_contra', false)
            ->assertJsonPath('account.normal_balance', Account::NORMAL_BALANCE_DEBIT)
            ->assertJsonPath('account.description', 'Small cash drawer')
            ->assertJsonPath('account.is_active', true);

        $this->assertSame(self::ACCOUNT_KEYS, array_keys($createResponse->json('account')));
        $this->assertDatabaseHas('accounts', ['code' => self::NEW_ACCOUNT_CODE, 'is_active' => true]);
        $this->assertSame(self::SEEDED_ACCOUNT_COUNT + 1, Account::count());
    }

    public function test_accountant_creates_account(): void
    {
        $this->withToken($this->createAccountantToken())
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload())
            ->assertCreated()
            ->assertJsonPath('account.code', self::NEW_ACCOUNT_CODE);
    }

    public function test_show_returns_account(): void
    {
        $cashAccount = $this->findSeededAccount('1010');

        $showResponse = $this->withToken($this->adminToken)
            ->getJson(self::ACCOUNTS_URL.'/'.$cashAccount->id)
            ->assertOk()
            ->assertJsonPath('account.id', $cashAccount->id)
            ->assertJsonPath('account.name', 'Cash on Hand')
            ->assertJsonPath('account.normal_balance', Account::NORMAL_BALANCE_DEBIT);

        $this->assertSame(self::ACCOUNT_KEYS, array_keys($showResponse->json('account')));
    }

    public function test_show_missing_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'/'.self::MISSING_ID)->assertNotFound();
        $this->withToken($this->adminToken)->getJson(self::ACCOUNTS_URL.'/abc')->assertNotFound();
    }

    public function test_update_changes_every_field_including_code_and_type(): void
    {
        $cashAccount = $this->findSeededAccount('1010');
        $updatedFields = [
            'code' => '2999',
            'name' => 'Renamed Liability',
            'type' => Account::TYPE_LIABILITY,
            'subtype' => Account::SUBTYPE_NON_CURRENT_LIABILITY,
            'is_contra' => true,
            'description' => 'Moved to liabilities',
        ];

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$cashAccount->id, $updatedFields)
            ->assertOk()
            ->assertJsonPath('account.id', $cashAccount->id)
            ->assertJsonPath('account.code', '2999')
            ->assertJsonPath('account.type', Account::TYPE_LIABILITY)
            ->assertJsonPath('account.subtype', Account::SUBTYPE_NON_CURRENT_LIABILITY)
            ->assertJsonPath('account.is_contra', true)
            ->assertJsonPath('account.normal_balance', Account::NORMAL_BALANCE_DEBIT)
            ->assertJsonPath('account.description', 'Moved to liabilities');

        $this->assertDatabaseHas('accounts', ['id' => $cashAccount->id, ...$updatedFields]);
    }

    public function test_update_keeps_own_code_and_recases_own_name(): void
    {
        $cashAccount = $this->findSeededAccount('1010');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$cashAccount->id, $this->buildAccountPayload(['code' => '1010', 'name' => 'CASH ON HAND']))
            ->assertOk()
            ->assertJsonPath('account.code', '1010')
            ->assertJsonPath('account.name', 'CASH ON HAND');
    }

    public function test_update_missing_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.self::MISSING_ID, $this->buildAccountPayload())
            ->assertNotFound();
        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/abc', $this->buildAccountPayload())
            ->assertNotFound();
    }

    public function test_update_ignores_is_active_in_payload(): void
    {
        $cashAccount = $this->findSeededAccount('1010');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$cashAccount->id, $this->buildAccountPayload(['code' => '1010', 'is_active' => false]))
            ->assertOk()
            ->assertJsonPath('account.is_active', true);

        $this->assertTrue($cashAccount->refresh()->is_active);
    }

    public function test_deactivate_and_reactivate_toggle_is_active(): void
    {
        $cashAccount = $this->findSeededAccount('1200');
        $accountUrl = self::ACCOUNTS_URL.'/'.$cashAccount->id;

        $this->withToken($this->adminToken)->putJson($accountUrl.'/deactivate')
            ->assertOk()
            ->assertJsonPath('account.is_active', false);
        $this->assertFalse($cashAccount->refresh()->is_active);

        $this->withToken($this->adminToken)->putJson($accountUrl.'/reactivate')
            ->assertOk()
            ->assertJsonPath('account.is_active', true);
        $this->assertTrue($cashAccount->refresh()->is_active);
    }

    public function test_deactivate_missing_returns_404(): void
    {
        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::ACCOUNTS_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_delete_returns_204_and_removes_row(): void
    {
        $newAccount = Account::factory()->create();

        $this->withToken($this->adminToken)->deleteJson(self::ACCOUNTS_URL.'/'.$newAccount->id)->assertNoContent();

        $this->assertDatabaseMissing('accounts', ['id' => $newAccount->id]);
    }

    public function test_delete_seeded_account_is_allowed_while_unused(): void
    {
        $cashAccount = $this->findSeededAccount('1200');

        $this->withToken($this->adminToken)->deleteJson(self::ACCOUNTS_URL.'/'.$cashAccount->id)->assertNoContent();

        $this->assertSame(self::SEEDED_ACCOUNT_COUNT - 1, Account::count());
    }

    public function test_delete_missing_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::ACCOUNTS_URL.'/'.self::MISSING_ID)->assertNotFound();
        $this->withToken($this->adminToken)->deleteJson(self::ACCOUNTS_URL.'/abc')->assertNotFound();
    }

    public function test_is_used_returns_false_for_every_seeded_account(): void
    {
        $seededAccounts = Account::all();

        $this->assertCount(self::SEEDED_ACCOUNT_COUNT, $seededAccounts);

        foreach ($seededAccounts as $seededAccount) {
            $this->assertFalse($seededAccount->isUsed());
        }
    }
}
