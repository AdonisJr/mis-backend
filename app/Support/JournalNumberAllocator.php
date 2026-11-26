<?php

namespace App\Support;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class JournalNumberAllocator
{
    public const JV_SERIES = 'journal_voucher';

    /** Must run inside the posting transaction. */
    public static function allocateNext(): string
    {
        $now = now();
        DB::table('receipt_counters')->insertOrIgnore(['series' => self::JV_SERIES, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $counterRow = DB::table('receipt_counters')->where('series', self::JV_SERIES)->lockForUpdate()->first();
        $nextNumber = $counterRow->last_number + 1;
        DB::table('receipt_counters')->where('series', self::JV_SERIES)->update(['last_number' => $nextNumber, 'updated_at' => $now]);

        // the increment lives in the caller's transaction: a rollback releases the number, so the series never skips or repeats one
        return sprintf(JournalEntry::NUMBER_FORMAT, $nextNumber);
    }
}
