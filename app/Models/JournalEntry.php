<?php

namespace App\Models;

use App\Support\PaymentAllocation;
use Brick\Math\BigDecimal;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_POSTED];

    public const DISPLAY_STATUS_DRAFT = 'draft';

    public const DISPLAY_STATUS_POSTED = 'posted';

    public const DISPLAY_STATUS_REVERSED = 'reversed';

    public const DISPLAY_STATUS_REVERSAL = 'reversal';

    public const DISPLAY_STATUSES = [
        self::DISPLAY_STATUS_DRAFT,
        self::DISPLAY_STATUS_POSTED,
        self::DISPLAY_STATUS_REVERSED,
        self::DISPLAY_STATUS_REVERSAL,
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_ASSESSMENT = 'assessment';

    public const SOURCE_PAYMENT = 'payment';

    public const BILLING_SOURCES = [self::SOURCE_ASSESSMENT, self::SOURCE_PAYMENT];

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_ASSESSMENT, self::SOURCE_PAYMENT];

    public const NUMBER_FORMAT = 'JV-%06d';

    public const MIN_LINES = 2;

    public const MAX_LINES = 100;

    public const TRANSACTION_ATTEMPTS = 3;

    public const ROW_RELATIONS = ['lines'];

    public const DETAIL_RELATIONS = ['lines.account', 'creator', 'poster', 'reversalOf', 'reversedBy'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['entry_date', 'description', 'reference'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::STATUS_DRAFT, 'source' => self::SOURCE_MANUAL];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'created_by' => 'integer',
            'posted_by' => 'integer',
            'reversal_of_id' => 'integer',
            'reversed_by_id' => 'integer',
            'source_id' => 'integer',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    /** @param Builder<JournalEntry> $query */
    public function scopeWithDisplayStatus(Builder $query, string $displayStatus): void
    {
        match ($displayStatus) {
            self::DISPLAY_STATUS_DRAFT => $query->where('status', self::STATUS_DRAFT),
            self::DISPLAY_STATUS_POSTED => $query->where('status', self::STATUS_POSTED)->whereNull('reversal_of_id')->whereNull('reversed_by_id'),
            self::DISPLAY_STATUS_REVERSED => $query->whereNotNull('reversed_by_id'),
            self::DISPLAY_STATUS_REVERSAL => $query->whereNotNull('reversal_of_id'),
        };
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    public function isBillingSourced(): bool
    {
        return in_array($this->source, self::BILLING_SOURCES, true);
    }

    public function canBeReversed(): bool
    {
        return $this->isPosted() && ! $this->isReversal() && ! $this->isReversed() && ! $this->isBillingSourced();
    }

    /** @return array{type: string, id: int}|null */
    public function buildSourceLink(): ?array
    {
        if (! $this->isBillingSourced()) {
            return null;
        }

        return ['type' => $this->source_type, 'id' => $this->source_id];
    }

    /** An unsaved entry carrying the billing record's three source fields. */
    public static function buildForSource(Assessment|Payment $record): self
    {
        $sourceType = $record instanceof Payment ? self::SOURCE_PAYMENT : self::SOURCE_ASSESSMENT;
        $entry = new self;
        $entry->forceFill(['source' => $sourceType, 'source_type' => $sourceType, 'source_id' => $record->id]);

        return $entry;
    }

    /** @param Builder<JournalEntry> $query */
    public function scopeOriginalsFor(Builder $query, self $sourceCarrier): void
    {
        $query->where('source_type', $sourceCarrier->source_type)
            ->where('source_id', $sourceCarrier->source_id)
            ->whereNull('reversal_of_id');
    }

    public function getDisplayStatus(): string
    {
        return match (true) {
            ! $this->isPosted() => self::DISPLAY_STATUS_DRAFT,
            $this->isReversal() => self::DISPLAY_STATUS_REVERSAL,
            $this->isReversed() => self::DISPLAY_STATUS_REVERSED,
            default => self::DISPLAY_STATUS_POSTED,
        };
    }

    public function calculateDebitTotal(): BigDecimal
    {
        return PaymentAllocation::sumAmounts($this->lines->pluck('debit'));
    }

    public function calculateCreditTotal(): BigDecimal
    {
        return PaymentAllocation::sumAmounts($this->lines->pluck('credit'));
    }
}
