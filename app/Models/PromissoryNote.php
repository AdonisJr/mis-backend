<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\PromissoryNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PromissoryNote extends Model
{
    /** @use HasFactory<PromissoryNoteFactory> */
    use HasFactory;

    public const STATUS_NONE = 'none';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BROKEN = 'broken';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    public const LIST_STATUSES = [self::STATUS_NONE, self::STATUS_ACTIVE, self::STATUS_BROKEN, self::STATUS_SETTLED, self::STATUS_CANCELLED];

    public const TRANSACTION_ATTEMPTS = 3;

    public const ROW_RELATIONS = ['student', 'schoolYear', 'granter', 'canceller'];

    private const MISSING_BALANCE_MESSAGE = 'Assign the year balance before deriving the status.';

    private ?BigDecimal $yearBalance = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'promised_amount',
        'promise_date',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'school_year_id' => 'integer',
            'granted_by' => 'integer',
            'cancelled_by' => 'integer',
            'promised_amount' => 'decimal:2',
            'promise_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<User, $this> */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @param Builder<PromissoryNote> $query */
    public function scopeActive(Builder $query): void
    {
        // the promise date still covers through its own Manila day, so the comparison is inclusive
        $query->whereNull('promissory_notes.cancelled_at')
            ->whereDate('promissory_notes.promise_date', '>=', today()->toDateString());
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isCancelled() && $this->promise_date->greaterThanOrEqualTo(today());
    }

    public function assignYearBalance(BigDecimal $yearBalance): static
    {
        $this->yearBalance = $yearBalance;

        return $this;
    }

    /** Derived on every read, never stored: cancelled, then settled, then active, else broken. */
    public function deriveStatus(): string
    {
        if ($this->isCancelled()) {
            return self::STATUS_CANCELLED;
        }

        if ($this->yearBalance === null) {
            throw new LogicException(self::MISSING_BALANCE_MESSAGE);
        }

        if (! $this->yearBalance->isPositive()) {
            return self::STATUS_SETTLED;
        }

        return $this->isActive() ? self::STATUS_ACTIVE : self::STATUS_BROKEN;
    }
}
