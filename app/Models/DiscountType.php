<?php

namespace App\Models;

use Database\Factories\DiscountTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountType extends Model
{
    /** @use HasFactory<DiscountTypeFactory> */
    use HasFactory;

    public const SOURCE_GOVERNMENT = 'government';

    public const SOURCE_SCHOOL = 'school';

    public const SOURCES = [self::SOURCE_GOVERNMENT, self::SOURCE_SCHOOL];

    public const VALUE_TYPE_FIXED = PaymentPlan::TYPE_FIXED;

    public const VALUE_TYPE_PERCENT = PaymentPlan::TYPE_PERCENT;

    public const VALUE_TYPES = [self::VALUE_TYPE_FIXED, self::VALUE_TYPE_PERCENT];

    public const BASE_TUITION = FeeItem::CATEGORY_TUITION;

    public const BASE_TOTAL = 'total';

    public const BASES = [self::BASE_TUITION, self::BASE_TOTAL];

    public const STAGES = [GradeLevel::STAGE_JHS, GradeLevel::STAGE_SHS];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'source',
        'value_type',
        'value',
        'base',
        'stages',
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
        return [
            'value' => 'decimal:2',
            'stages' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<StudentDiscount, $this> */
    public function studentDiscounts(): HasMany
    {
        return $this->hasMany(StudentDiscount::class);
    }

    /** @param Builder<DiscountType> $query */
    public function scopeWithSameName(Builder $query, DiscountType $candidate): void
    {
        // LOWER() on both sides keeps the comparison case-insensitive on every database, and the own id is excluded for updates
        $query->whereRaw('LOWER(name) = LOWER(?)', [$candidate->name])
            ->when($candidate->getKey(), fn (Builder $nameQuery) => $nameQuery->whereKeyNot($candidate->getKey()));
    }
}
