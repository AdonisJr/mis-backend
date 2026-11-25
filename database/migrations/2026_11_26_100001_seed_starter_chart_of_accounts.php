<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEEDED_COLUMNS = ['code', 'name', 'type', 'subtype', 'is_contra', 'description', 'is_active'];

    // literal copies, not the Account catalog constants: this migration must insert and compare the same rows even if the Account catalog changes later
    private const SEED_ROWS = [
        ['code' => '1010', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1020', 'name' => 'Cash in Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1100', 'name' => 'Accounts Receivable – Students', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1110', 'name' => 'Receivable – Government (ESC / SHS Voucher)', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1190', 'name' => 'Allowance for Doubtful Accounts', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => true],
        ['code' => '1200', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1300', 'name' => 'School Supplies Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'is_contra' => false],
        ['code' => '1500', 'name' => 'Property and Equipment', 'type' => 'asset', 'subtype' => 'non_current_asset', 'is_contra' => false],
        ['code' => '1590', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'subtype' => 'non_current_asset', 'is_contra' => true],
        ['code' => '2010', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_contra' => false],
        ['code' => '2020', 'name' => 'Accrued Expenses', 'type' => 'liability', 'subtype' => 'current_liability', 'is_contra' => false],
        ['code' => '2030', 'name' => 'Unearned Tuition and Fees', 'type' => 'liability', 'subtype' => 'current_liability', 'is_contra' => false],
        ['code' => '2100', 'name' => 'Withholding Taxes Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_contra' => false],
        ['code' => '2110', 'name' => 'SSS, PhilHealth and Pag-IBIG Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'is_contra' => false],
        ['code' => '3010', 'name' => 'Capital / Fund Balance', 'type' => 'equity', 'subtype' => 'equity', 'is_contra' => false],
        ['code' => '3020', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity', 'is_contra' => false],
        ['code' => '4010', 'name' => 'Tuition Fees', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => false],
        ['code' => '4020', 'name' => 'Miscellaneous Fees', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => false],
        ['code' => '4030', 'name' => 'Other School Fees', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => false],
        ['code' => '4040', 'name' => 'Installment Surcharge Income', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => false],
        ['code' => '4090', 'name' => 'Scholarships and Discounts', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => true],
        ['code' => '4095', 'name' => 'Cash Payment Discounts', 'type' => 'revenue', 'subtype' => 'operating_revenue', 'is_contra' => true],
        ['code' => '4100', 'name' => 'Other Income', 'type' => 'revenue', 'subtype' => 'other_income', 'is_contra' => false],
        ['code' => '5010', 'name' => 'Salaries and Wages', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5020', 'name' => 'Employee Benefits', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5030', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5040', 'name' => 'Rent', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5050', 'name' => 'Supplies Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5060', 'name' => 'Repairs and Maintenance', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5070', 'name' => 'Depreciation Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5080', 'name' => 'Bad Debts Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5090', 'name' => 'Taxes and Licenses', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
        ['code' => '5100', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'is_contra' => false],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->insertMissingRows();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::SEED_ROWS as $seedRow) {
            $this->deleteUntouchedSeedRow($seedRow);
        }
    }

    private function insertMissingRows(): void
    {
        $takenCodes = DB::table('accounts')->pluck('code')->all();
        $takenNames = DB::table('accounts')->pluck('name')->map(fn (string $name) => mb_strtolower($name))->all();
        $missingRows = array_filter(self::SEED_ROWS, fn (array $seedRow) => ! in_array($seedRow['code'], $takenCodes, true) && ! in_array(mb_strtolower($seedRow['name']), $takenNames, true));

        if ($missingRows === []) {
            return;
        }

        $insertedAt = now();

        DB::table('accounts')->insert(array_map(fn (array $seedRow) => [...$seedRow, 'description' => null, 'is_active' => true, 'created_at' => $insertedAt, 'updated_at' => $insertedAt], array_values($missingRows)));
    }

    private function deleteUntouchedSeedRow(array $seedRow): void
    {
        $account = Account::where('code', $seedRow['code'])->first();

        if (! $account || $account->isUsed() || ! $this->isSeedRowUntouched($account, $seedRow)) {
            return;
        }

        $account->delete();
    }

    private function isSeedRowUntouched(Account $account, array $seedRow): bool
    {
        // compared in PHP on cast values: a case-insensitive collation would hide a case-only rename in SQL
        return $account->only(self::SEEDED_COLUMNS) === [...$seedRow, 'description' => null, 'is_active' => true];
    }
};
