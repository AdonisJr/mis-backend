<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class ReceiptNumberAllocator
{
    public const AR_SERIES = 'acknowledgment_receipt';

    /** Must run inside the record transaction. */
    public static function allocateNext(): string
    {
        $now = now();
        DB::table('receipt_counters')->insertOrIgnore(['series' => self::AR_SERIES, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $counterRow = DB::table('receipt_counters')->where('series', self::AR_SERIES)->lockForUpdate()->first();
        $nextNumber = $counterRow->last_number + 1;
        DB::table('receipt_counters')->where('series', self::AR_SERIES)->update(['last_number' => $nextNumber, 'updated_at' => $now]);

        // the increment lives in the caller's transaction: a rollback releases the number, so the series never skips or repeats one
        return sprintf(Payment::AR_NUMBER_FORMAT, $nextNumber);
    }
}
