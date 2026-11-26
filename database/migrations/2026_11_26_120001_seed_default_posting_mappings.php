<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // literal copies, not the PostingMapping catalog constants: this migration must insert and compare the same rows even if the catalog changes later
    private const SEED_ROWS = [
        ['purpose' => 'ar_students', 'code' => '1100'],
        ['purpose' => 'receivable_government', 'code' => '1110'],
        ['purpose' => 'revenue_tuition', 'code' => '4010'],
        ['purpose' => 'revenue_miscellaneous', 'code' => '4020'],
        ['purpose' => 'revenue_other', 'code' => '4030'],
        ['purpose' => 'installment_surcharge', 'code' => '4040'],
        ['purpose' => 'school_discounts', 'code' => '4090'],
        ['purpose' => 'cash_payment_discount', 'code' => '4095'],
        ['purpose' => 'cash_cash', 'code' => '1010'],
        ['purpose' => 'cash_check', 'code' => '1010'],
        ['purpose' => 'cash_e_wallet', 'code' => '1020'],
        ['purpose' => 'cash_bank_transfer', 'code' => '1020'],
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
            $this->deleteUntouchedRow($seedRow);
        }
    }

    private function insertMissingRows(): void
    {
        $takenPurposes = DB::table('posting_mappings')->pluck('purpose')->all();
        $accountIdsByCode = DB::table('accounts')->pluck('id', 'code')->all();
        $missingRows = array_filter(self::SEED_ROWS, fn (array $seedRow) => ! in_array($seedRow['purpose'], $takenPurposes, true) && isset($accountIdsByCode[$seedRow['code']]));

        if ($missingRows === []) {
            return;
        }

        $insertedAt = now();

        DB::table('posting_mappings')->insert(array_map(fn (array $seedRow) => [
            'purpose' => $seedRow['purpose'],
            'account_id' => $accountIdsByCode[$seedRow['code']],
            'created_at' => $insertedAt,
            'updated_at' => $insertedAt,
        ], array_values($missingRows)));
    }

    private function deleteUntouchedRow(array $seedRow): void
    {
        $accountId = DB::table('accounts')->where('code', $seedRow['code'])->value('id');

        if ($accountId === null) {
            return;
        }

        DB::table('posting_mappings')->where('purpose', $seedRow['purpose'])->where('account_id', $accountId)->delete();
    }
};
