<?php

namespace App\Models;

use Database\Factories\JournalLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    /** @use HasFactory<JournalLineFactory> */
    use HasFactory;

    private const ZERO_AMOUNT = '0';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['account_id', 'debit', 'credit', 'memo'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // decimal:2 reads back as an exact two-decimal string, never a float
        return [
            'journal_entry_id' => 'integer',
            'account_id' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * Keep only the persisted columns of each line; validated() also carries unvalidated keys.
     *
     * @param  iterable<array<string, mixed>|JournalLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function buildRows(iterable $lines): array
    {
        $lineRows = [];

        foreach ($lines as $line) {
            $lineRows[] = [
                'account_id' => data_get($line, 'account_id'),
                'debit' => data_get($line, 'debit') ?? self::ZERO_AMOUNT,
                'credit' => data_get($line, 'credit') ?? self::ZERO_AMOUNT,
                'memo' => data_get($line, 'memo'),
            ];
        }

        return $lineRows;
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
