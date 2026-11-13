<?php

namespace App\Models;

use Database\Factories\FeeItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeItem extends Model
{
    /** @use HasFactory<FeeItemFactory> */
    use HasFactory;

    public const CATEGORY_TUITION = 'tuition';

    public const CATEGORY_MISCELLANEOUS = 'miscellaneous';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [self::CATEGORY_TUITION, self::CATEGORY_MISCELLANEOUS, self::CATEGORY_OTHER];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'description',
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
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<FeeScheduleLine, $this> */
    public function scheduleLines(): HasMany
    {
        return $this->hasMany(FeeScheduleLine::class);
    }

    /** @param Builder<FeeItem> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
