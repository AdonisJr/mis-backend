<?php

namespace App\Models;

use Database\Factories\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use HasFactory;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENT = 'percent';

    public const DOWN_PAYMENT_TYPES = [self::TYPE_FIXED, self::TYPE_PERCENT];

    public const ADJUSTMENT_TYPES = [self::TYPE_FIXED, self::TYPE_PERCENT];

    public const KIND_DISCOUNT = 'discount';

    public const KIND_SURCHARGE = 'surcharge';

    public const ADJUSTMENT_KINDS = [self::KIND_DISCOUNT, self::KIND_SURCHARGE];

    public const MAX_INSTALLMENTS = 12;

    public const ADJUSTMENT_CATEGORY = FeeItem::CATEGORY_TUITION;

    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_year_id',
        'name',
        'description',
        'down_payment_type',
        'down_payment_value',
        'adjustment_kind',
        'adjustment_type',
        'adjustment_value',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids as digit strings; the casts keep the JSON numeric and the money as strings
        return [
            'school_year_id' => 'integer',
            'is_active' => 'boolean',
            'down_payment_value' => 'decimal:2',
            'adjustment_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return HasMany<PaymentPlanInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('sequence');
    }

    /** @param Builder<PaymentPlan> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<PaymentPlan> $query */
    public function scopeWithSameName(Builder $query, PaymentPlan $candidate): void
    {
        // LOWER() on both sides keeps the comparison case-insensitive on every database, and the own id is excluded for updates
        $query->where('school_year_id', $candidate->school_year_id)
            ->whereRaw('LOWER(name) = LOWER(?)', [$candidate->name])
            ->when($candidate->getKey(), fn (Builder $nameQuery) => $nameQuery->whereKeyNot($candidate->getKey()));
    }

    public function isCash(): bool
    {
        return $this->installments->isEmpty();
    }
}
