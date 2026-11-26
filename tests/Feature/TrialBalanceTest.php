<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithReports;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use InteractsWithReports;
    use RefreshDatabase;

    private const TRIAL_BALANCE = 'trial-balance';

    private const REPORT_KEYS = ['as_of', 'type', 'include_zero', 'rows', 'totals', 'is_balanced'];

    private const ROW_KEYS = ['id', 'code', 'name', 'type', 'subtype', 'is_contra', 'is_active', 'debit', 'credit'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_scenario_rows_carry_the_normal_side_balances_and_exact_keys(): void
    {
        $this->seedBillingLedger();

        $trialBalance = $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)->assertOk()->json('trial_balance');
        $rowsByCode = $this->keyRowsByCode($trialBalance['rows']);

        $this->assertSame(self::REPORT_KEYS, array_keys($trialBalance));
        $this->assertSame(self::ROW_KEYS, array_keys($trialBalance['rows'][0]));
        $this->assertSame(['1010', '1100', '4010', '5030'], $this->listRowCodes($trialBalance['rows']));
        $this->assertSame(['700.00', '0.00'], [$rowsByCode['1010']['debit'], $rowsByCode['1010']['credit']]);
        $this->assertSame(['9000.00', '0.00'], [$rowsByCode['1100']['debit'], $rowsByCode['1100']['credit']]);
        $this->assertSame(['0.00', '10000.00'], [$rowsByCode['4010']['debit'], $rowsByCode['4010']['credit']]);
        $this->assertSame(['300.00', '0.00'], [$rowsByCode['5030']['debit'], $rowsByCode['5030']['credit']]);
        $this->assertSame([self::AS_OF, null, false], [$trialBalance['as_of'], $trialBalance['type'], $trialBalance['include_zero']]);
    }

    public function test_totals_sum_the_rows_and_the_ledger_is_balanced(): void
    {
        $this->seedBillingLedger();

        $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('trial_balance.totals', ['debit' => '10000.00', 'credit' => '10000.00'])
            ->assertJsonPath('trial_balance.is_balanced', true);
    }

    public function test_draft_entry_is_excluded(): void
    {
        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, [
            'entry_date' => '2030-06-15',
            'description' => 'Draft only',
            'lines' => [
                ['account_id' => $this->findAccountByCode('5040')->id, 'debit' => '800.00'],
                ['account_id' => $this->findAccountByCode('1010')->id, 'credit' => '800.00'],
            ],
        ])->assertCreated();

        $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('trial_balance.rows', [])
            ->assertJsonPath('trial_balance.totals', ['debit' => '0.00', 'credit' => '0.00']);
    }

    public function test_reversal_nets_out_and_a_later_reversal_is_ignored_at_an_earlier_date(): void
    {
        $entryId = $this->postManualEntry('2030-06-10', [['5040', '400.00', self::ZERO], ['1010', self::ZERO, '400.00']]);
        $this->reverseManualEntry($entryId, '2030-06-20');

        $this->fetchReport(self::TRIAL_BALANCE, 'as_of=2030-06-15')
            ->assertOk()
            ->assertJsonPath('trial_balance.totals', ['debit' => '400.00', 'credit' => '400.00']);
        $this->fetchReport(self::TRIAL_BALANCE, 'as_of=2030-06-20')
            ->assertOk()
            ->assertJsonPath('trial_balance.rows', [])
            ->assertJsonPath('trial_balance.is_balanced', true);
    }

    public function test_inactive_account_with_a_balance_is_listed(): void
    {
        $this->postManualEntry('2030-06-15', [['5030', '300.00', self::ZERO], ['1010', self::ZERO, '300.00']]);
        $this->deactivateSeeded('5030');

        $rowsByCode = $this->keyRowsByCode($this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)->assertOk()->json('trial_balance.rows'));

        $this->assertSame([false, '300.00'], [$rowsByCode['5030']['is_active'], $rowsByCode['5030']['debit']]);
    }

    public function test_as_of_date_is_inclusive(): void
    {
        $this->postManualEntry('2030-06-15', [['5030', '300.00', self::ZERO], ['1010', self::ZERO, '300.00']]);
        $this->postManualEntry('2030-06-16', [['5040', '900.00', self::ZERO], ['1010', self::ZERO, '900.00']]);

        $rowsByCode = $this->keyRowsByCode($this->fetchReport(self::TRIAL_BALANCE, 'as_of=2030-06-15')->assertOk()->json('trial_balance.rows'));

        $this->assertSame(['1010', '5030'], $this->listRowCodes(array_values($rowsByCode)));
        $this->assertSame('300.00', $rowsByCode['5030']['debit']);
    }

    public function test_type_filter_keeps_one_type_and_the_ledger_stays_balanced(): void
    {
        $this->seedBillingLedger();

        $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF.'&type=expense')
            ->assertOk()
            ->assertJsonPath('trial_balance.type', 'expense')
            ->assertJsonCount(1, 'trial_balance.rows')
            ->assertJsonPath('trial_balance.rows.0.code', '5030')
            ->assertJsonPath('trial_balance.totals', ['debit' => '300.00', 'credit' => '0.00'])
            ->assertJsonPath('trial_balance.is_balanced', true);
    }

    public function test_include_zero_adds_active_zero_accounts_but_not_inactive_ones(): void
    {
        $this->postManualEntry('2030-06-15', [['5030', '300.00', self::ZERO], ['1010', self::ZERO, '300.00']]);
        $this->deactivateSeeded('5100');

        $trialBalance = $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF.'&include_zero=1')->assertOk()->json('trial_balance');
        $rowsByCode = $this->keyRowsByCode($trialBalance['rows']);

        $this->assertTrue($trialBalance['include_zero']);
        $this->assertSame([self::ZERO, self::ZERO], [$rowsByCode['1500']['debit'], $rowsByCode['1500']['credit']]);
        $this->assertArrayNotHasKey('5100', $rowsByCode);
        $this->assertSame('300.00', $rowsByCode['5030']['debit']);
    }

    public function test_abnormal_balance_shows_on_the_opposite_side(): void
    {
        $this->postManualEntry('2030-06-15', [['5030', '50.00', self::ZERO], ['1020', self::ZERO, '50.00']]);

        $rowsByCode = $this->keyRowsByCode($this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)->assertOk()->json('trial_balance.rows'));

        $this->assertSame([self::ZERO, '50.00'], [$rowsByCode['1020']['debit'], $rowsByCode['1020']['credit']]);
    }

    public function test_unbalanced_posted_data_reads_out_of_balance_without_failing(): void
    {
        $this->insertUnbalancedEntry('2030-06-15');

        $this->fetchReport(self::TRIAL_BALANCE, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('trial_balance.totals', ['debit' => '100.00', 'credit' => '0.00'])
            ->assertJsonPath('trial_balance.is_balanced', false);
    }

    public function test_invalid_type_and_include_zero_are_rejected(): void
    {
        $this->fetchReport(self::TRIAL_BALANCE, 'type=cash&include_zero=yes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'include_zero']);
    }

    public function test_missing_as_of_defaults_to_today(): void
    {
        $this->fetchReport(self::TRIAL_BALANCE)->assertOk()->assertJsonPath('trial_balance.as_of', self::AS_OF);
    }
}
