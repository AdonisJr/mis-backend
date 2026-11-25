<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAccounts;
use Tests\TestCase;

class AccountNormalBalanceTest extends TestCase
{
    use InteractsWithAccounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function provideNormalBalances(): array
    {
        $debit = Account::NORMAL_BALANCE_DEBIT;
        $credit = Account::NORMAL_BALANCE_CREDIT;

        return [
            'asset' => [Account::TYPE_ASSET, false, $debit],
            'asset contra' => [Account::TYPE_ASSET, true, $credit],
            'liability' => [Account::TYPE_LIABILITY, false, $credit],
            'liability contra' => [Account::TYPE_LIABILITY, true, $debit],
            'equity' => [Account::TYPE_EQUITY, false, $credit],
            'equity contra' => [Account::TYPE_EQUITY, true, $debit],
            'revenue' => [Account::TYPE_REVENUE, false, $credit],
            'revenue contra' => [Account::TYPE_REVENUE, true, $debit],
            'expense' => [Account::TYPE_EXPENSE, false, $debit],
            'expense contra' => [Account::TYPE_EXPENSE, true, $credit],
        ];
    }

    #[DataProvider('provideNormalBalances')]
    public function test_normal_balance_by_type_and_contra(string $accountType, bool $isContra, string $expectedBalance): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ACCOUNTS_URL, $this->buildAccountPayload([
                'type' => $accountType,
                'subtype' => Account::SUBTYPES_BY_TYPE[$accountType][0],
                'is_contra' => $isContra,
            ]))
            ->assertCreated()
            ->assertJsonPath('account.normal_balance', $expectedBalance);
    }

    public function test_seeded_contra_accounts_flip_balance(): void
    {
        foreach (['1190' => 'credit', '1590' => 'credit', '4090' => 'debit', '4095' => 'debit'] as $code => $expectedBalance) {
            $this->withToken($this->adminToken)
                ->getJson(self::ACCOUNTS_URL.'/'.$this->findSeededAccount($code)->id)
                ->assertOk()
                ->assertJsonPath('account.is_contra', true)
                ->assertJsonPath('account.normal_balance', $expectedBalance);
        }
    }

    public function test_normal_balance_follows_type_change_on_update(): void
    {
        $cashAccount = $this->findSeededAccount('1010');

        $this->withToken($this->adminToken)
            ->putJson(self::ACCOUNTS_URL.'/'.$cashAccount->id, $this->buildAccountPayload([
                'code' => '1010',
                'name' => 'Cash on Hand',
                'type' => Account::TYPE_LIABILITY,
                'subtype' => Account::SUBTYPE_CURRENT_LIABILITY,
            ]))
            ->assertOk()
            ->assertJsonPath('account.normal_balance', Account::NORMAL_BALANCE_CREDIT);
    }
}
