<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithReports;
use Tests\TestCase;

class IncomeStatementTest extends TestCase
{
    use InteractsWithReports;
    use RefreshDatabase;

    private const INCOME_STATEMENT = 'income-statement';

    private const YEAR_QUERY = 'from=2030-01-01&to=2030-07-01';

    private const STATEMENT_KEYS = ['from', 'to', 'revenue', 'contra_revenue', 'net_revenue', 'other_income', 'operating_expenses', 'other_expenses', 'net_income'];

    private const ROW_KEYS = ['id', 'code', 'name', 'is_contra', 'is_active', 'amount'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_scenario_sections_subtotals_and_net_income(): void
    {
        $this->seedBillingLedger();

        $statementResponse = $this->fetchReport(self::INCOME_STATEMENT, self::YEAR_QUERY)
            ->assertOk()
            ->assertJsonPath('income_statement.revenue.subtotal', '10000.00')
            ->assertJsonPath('income_statement.revenue.rows.0.code', '4010')
            ->assertJsonPath('income_statement.contra_revenue', ['rows' => [], 'subtotal' => '0.00'])
            ->assertJsonPath('income_statement.net_revenue', '10000.00')
            ->assertJsonPath('income_statement.other_income', ['rows' => [], 'subtotal' => '0.00'])
            ->assertJsonPath('income_statement.operating_expenses.subtotal', '300.00')
            ->assertJsonPath('income_statement.operating_expenses.rows.0.code', '5030')
            ->assertJsonPath('income_statement.other_expenses', ['rows' => [], 'subtotal' => '0.00'])
            ->assertJsonPath('income_statement.net_income', '9700.00');

        $this->assertSame(self::STATEMENT_KEYS, array_keys($statementResponse->json('income_statement')));
        $this->assertSame(self::ROW_KEYS, array_keys($statementResponse->json('income_statement.revenue.rows.0')));
        $this->assertSame(['rows', 'subtotal'], array_keys($statementResponse->json('income_statement.revenue')));
    }

    public function test_contra_revenue_is_deducted_in_its_own_section(): void
    {
        $this->postManualEntry('2030-06-10', [['1100', '3000.00', self::ZERO], ['4010', self::ZERO, '3000.00']]);
        $this->postManualEntry('2030-06-11', [['4090', '400.00', self::ZERO], ['1100', self::ZERO, '400.00']]);

        $this->fetchReport(self::INCOME_STATEMENT, self::YEAR_QUERY)
            ->assertOk()
            ->assertJsonPath('income_statement.revenue.subtotal', '3000.00')
            ->assertJsonPath('income_statement.contra_revenue.rows.0.code', '4090')
            ->assertJsonPath('income_statement.contra_revenue.rows.0.amount', '-400.00')
            ->assertJsonPath('income_statement.contra_revenue.rows.0.is_contra', true)
            ->assertJsonPath('income_statement.contra_revenue.subtotal', '-400.00')
            ->assertJsonPath('income_statement.net_revenue', '2600.00')
            ->assertJsonPath('income_statement.net_income', '2600.00');
    }

    public function test_contra_operating_expense_reduces_the_expense_section(): void
    {
        Account::factory()->contra()->create(['code' => '5999', 'type' => Account::TYPE_EXPENSE, 'subtype' => Account::SUBTYPE_OPERATING_EXPENSE]);
        $this->postManualEntry('2030-06-10', [['5030', '1000.00', self::ZERO], ['1010', self::ZERO, '1000.00']]);
        $this->postManualEntry('2030-06-11', [['1010', '100.00', self::ZERO], ['5999', self::ZERO, '100.00']]);

        $this->fetchReport(self::INCOME_STATEMENT, self::YEAR_QUERY)
            ->assertOk()
            ->assertJsonPath('income_statement.operating_expenses.subtotal', '900.00')
            ->assertJsonPath('income_statement.net_income', '-900.00');
    }

    public function test_net_loss_is_negative(): void
    {
        $this->postManualEntry('2030-06-10', [['5030', '1000.00', self::ZERO], ['1010', self::ZERO, '1000.00']]);
        $this->postManualEntry('2030-06-11', [['1010', '300.00', self::ZERO], ['4010', self::ZERO, '300.00']]);

        $this->fetchReport(self::INCOME_STATEMENT, self::YEAR_QUERY)
            ->assertOk()
            ->assertJsonPath('income_statement.net_income', '-700.00');
    }

    public function test_range_edges_are_inclusive_and_outside_days_are_excluded(): void
    {
        foreach ([['2030-05-31', '10.00'], ['2030-06-01', '20.00'], ['2030-06-30', '30.00'], ['2030-07-01', '40.00']] as [$entryDate, $amount]) {
            $this->postManualEntry($entryDate, [['5030', $amount, self::ZERO], ['1010', self::ZERO, $amount]]);
        }

        $this->fetchReport(self::INCOME_STATEMENT, 'from=2030-06-01&to=2030-06-30')
            ->assertOk()
            ->assertJsonPath('income_statement.operating_expenses.subtotal', '50.00')
            ->assertJsonPath('income_statement.net_income', '-50.00');
    }

    public function test_draft_and_reversed_pair_add_nothing(): void
    {
        $this->withToken($this->adminToken)->postJson(self::JOURNAL_ENTRIES_URL, [
            'entry_date' => '2030-06-15',
            'description' => 'Draft only',
            'lines' => [
                ['account_id' => $this->findAccountByCode('1010')->id, 'debit' => '800.00'],
                ['account_id' => $this->findAccountByCode('4010')->id, 'credit' => '800.00'],
            ],
        ])->assertCreated();
        $entryId = $this->postManualEntry('2030-06-10', [['1010', '500.00', self::ZERO], ['4010', self::ZERO, '500.00']]);
        $this->reverseManualEntry($entryId, '2030-06-20');

        $this->fetchReport(self::INCOME_STATEMENT, 'from=2030-06-01&to=2030-06-30')
            ->assertOk()
            ->assertJsonPath('income_statement.revenue', ['rows' => [], 'subtotal' => '0.00'])
            ->assertJsonPath('income_statement.net_income', '0.00');
    }

    public function test_from_after_to_is_rejected_on_to(): void
    {
        $this->fetchReport(self::INCOME_STATEMENT, 'from=2030-06-30&to=2030-06-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_missing_dates_default_to_the_year_to_date(): void
    {
        $this->fetchReport(self::INCOME_STATEMENT)
            ->assertOk()
            ->assertJsonPath('income_statement.from', '2030-01-01')
            ->assertJsonPath('income_statement.to', self::AS_OF);
    }
}
