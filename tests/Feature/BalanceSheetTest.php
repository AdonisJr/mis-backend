<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithReports;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use InteractsWithReports;
    use RefreshDatabase;

    private const BALANCE_SHEET = 'balance-sheet';

    private const SHEET_KEYS = ['as_of', 'current_year_start', 'assets', 'liabilities', 'equity', 'total_liabilities_and_equity', 'is_balanced'];

    private const EQUITY_KEYS = ['groups', 'net_income_current_year', 'net_income_prior_years', 'total'];

    private const GROUP_KEYS = ['subtype', 'rows', 'subtotal'];

    private const ROW_KEYS = ['id', 'code', 'name', 'is_contra', 'is_active', 'amount'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_scenario_groups_follow_subtype_order_with_empty_groups_present(): void
    {
        $this->seedBillingLedger();

        $balanceSheet = $this->fetchReport(self::BALANCE_SHEET, 'as_of='.self::AS_OF)->assertOk()->json('balance_sheet');

        $this->assertSame(self::SHEET_KEYS, array_keys($balanceSheet));
        $this->assertSame(['groups', 'total'], array_keys($balanceSheet['assets']));
        $this->assertSame(self::EQUITY_KEYS, array_keys($balanceSheet['equity']));
        $this->assertSame(self::GROUP_KEYS, array_keys($balanceSheet['assets']['groups'][0]));
        $this->assertSame(self::ROW_KEYS, array_keys($balanceSheet['assets']['groups'][0]['rows'][0]));
        $this->assertSame(['current_asset', 'non_current_asset'], array_column($balanceSheet['assets']['groups'], 'subtype'));
        $this->assertSame(['current_liability', 'non_current_liability'], array_column($balanceSheet['liabilities']['groups'], 'subtype'));
        $this->assertSame(['equity'], array_column($balanceSheet['equity']['groups'], 'subtype'));
        $this->assertSame([], $balanceSheet['assets']['groups'][1]['rows']);
        $this->assertSame(['1010', '1100'], $this->listRowCodes($balanceSheet['assets']['groups'][0]['rows']));
        $this->assertSame(['2030-01-01', '9700.00', '0.00', '9700.00'], [$balanceSheet['current_year_start'], $balanceSheet['equity']['net_income_current_year'], $balanceSheet['equity']['net_income_prior_years'], $balanceSheet['equity']['total']]);
        $this->assertSame(['9700.00', '0.00', '9700.00', true], [$balanceSheet['assets']['total'], $balanceSheet['liabilities']['total'], $balanceSheet['total_liabilities_and_equity'], $balanceSheet['is_balanced']]);
    }

    public function test_contra_asset_is_deducted_inside_its_group(): void
    {
        $this->postManualEntry('2030-06-10', [['1500', '2000.00', self::ZERO], ['3010', self::ZERO, '2000.00']]);
        $this->postManualEntry('2030-06-11', [['5070', '150.00', self::ZERO], ['1590', self::ZERO, '150.00']]);

        $balanceSheet = $this->fetchReport(self::BALANCE_SHEET, 'as_of='.self::AS_OF)->assertOk()->json('balance_sheet');
        $nonCurrentGroup = $balanceSheet['assets']['groups'][1];
        $rowsByCode = $this->keyRowsByCode($nonCurrentGroup['rows']);

        $this->assertSame('1850.00', $nonCurrentGroup['subtotal']);
        $this->assertSame(['2000.00', '-150.00'], [$rowsByCode['1500']['amount'], $rowsByCode['1590']['amount']]);
        $this->assertTrue($balanceSheet['is_balanced']);
    }

    public function test_net_income_splits_at_the_start_of_the_year(): void
    {
        $this->seedYearBoundaryEntries();

        $this->fetchReport(self::BALANCE_SHEET, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('balance_sheet.equity.net_income_prior_years', '800.00')
            ->assertJsonPath('balance_sheet.equity.net_income_current_year', '-200.00')
            ->assertJsonPath('balance_sheet.equity.total', '600.00')
            ->assertJsonPath('balance_sheet.is_balanced', true);
    }

    public function test_as_of_the_last_day_of_the_prior_year_moves_the_income_to_the_current_line(): void
    {
        $this->seedYearBoundaryEntries();

        $this->fetchReport(self::BALANCE_SHEET, 'as_of=2029-12-31')
            ->assertOk()
            ->assertJsonPath('balance_sheet.current_year_start', '2029-01-01')
            ->assertJsonPath('balance_sheet.equity.net_income_current_year', '800.00')
            ->assertJsonPath('balance_sheet.equity.net_income_prior_years', '0.00')
            ->assertJsonPath('balance_sheet.is_balanced', true);
    }

    public function test_entries_after_as_of_are_ignored(): void
    {
        $this->postManualEntry('2030-06-20', [['1010', '100.00', self::ZERO], ['3010', self::ZERO, '100.00']]);

        $this->fetchReport(self::BALANCE_SHEET, 'as_of=2030-06-19')->assertOk()->assertJsonPath('balance_sheet.assets.total', '0.00');
        $this->fetchReport(self::BALANCE_SHEET, 'as_of=2030-06-20')->assertOk()->assertJsonPath('balance_sheet.assets.total', '100.00');
    }

    public function test_inactive_account_with_a_balance_is_listed(): void
    {
        $this->postManualEntry('2030-06-15', [['1500', '500.00', self::ZERO], ['3010', self::ZERO, '500.00']]);
        $this->deactivateSeeded('1500');

        $this->fetchReport(self::BALANCE_SHEET, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('balance_sheet.assets.groups.1.rows.0.code', '1500')
            ->assertJsonPath('balance_sheet.assets.groups.1.rows.0.is_active', false);
    }

    public function test_unbalanced_posted_data_reads_out_of_balance_without_failing(): void
    {
        $this->insertUnbalancedEntry('2030-06-15');

        $this->fetchReport(self::BALANCE_SHEET, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('balance_sheet.assets.total', '100.00')
            ->assertJsonPath('balance_sheet.total_liabilities_and_equity', '0.00')
            ->assertJsonPath('balance_sheet.is_balanced', false);
    }

    public function test_missing_as_of_defaults_to_today(): void
    {
        $this->fetchReport(self::BALANCE_SHEET)->assertOk()->assertJsonPath('balance_sheet.as_of', self::AS_OF);
    }

    private function seedYearBoundaryEntries(): void
    {
        $this->postManualEntry('2029-12-31', [['1010', '800.00', self::ZERO], ['4100', self::ZERO, '800.00']]);
        $this->postManualEntry('2030-01-01', [['5040', '200.00', self::ZERO], ['1010', self::ZERO, '200.00']]);
    }
}
