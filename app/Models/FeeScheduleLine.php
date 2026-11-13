<?php

namespace App\Models;

use Database\Factories\FeeScheduleLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeScheduleLine extends Model
{
    /** @use HasFactory<FeeScheduleLineFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'fee_schedule_id',
        'fee_item_id',
        'amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // decimal:2 reads back as an exact two-decimal string, never a float
        return [
            'fee_schedule_id' => 'integer',
            'fee_item_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Keep only the persisted columns of each line; validated() also carries unvalidated keys.
     *
     * @param  iterable<array<string, mixed>|FeeScheduleLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function buildRows(iterable $lines): array
    {
        $lineRows = [];

        foreach ($lines as $line) {
            $lineRows[] = [
                'fee_item_id' => data_get($line, 'fee_item_id'),
                'amount' => data_get($line, 'amount'),
            ];
        }

        return $lineRows;
    }

    /** @return BelongsTo<FeeSchedule, $this> */
    public function feeSchedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class);
    }

    /** @return BelongsTo<FeeItem, $this> */
    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }
}
