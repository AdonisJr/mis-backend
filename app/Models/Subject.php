<?php

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    public const TYPE_JHS_LEARNING_AREA = 'jhs_learning_area';

    public const TYPE_SHS_CORE = 'shs_core';

    public const TYPE_SHS_APPLIED = 'shs_applied';

    public const TYPE_SHS_SPECIALIZED = 'shs_specialized';

    public const TYPES = [self::TYPE_JHS_LEARNING_AREA, self::TYPE_SHS_CORE, self::TYPE_SHS_APPLIED, self::TYPE_SHS_SPECIALIZED];

    public const TYPE_STAGES = [
        self::TYPE_JHS_LEARNING_AREA => GradeLevel::STAGE_JHS,
        self::TYPE_SHS_CORE => GradeLevel::STAGE_SHS,
        self::TYPE_SHS_APPLIED => GradeLevel::STAGE_SHS,
        self::TYPE_SHS_SPECIALIZED => GradeLevel::STAGE_SHS,
    ];

    public const WEEKLY_HOURS_MIN = '0.5';

    public const WEEKLY_HOURS_MAX = '40';

    public const WEEKLY_HOURS_STEP = '0.5';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'type', 'weekly_hours', 'description'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'weekly_hours' => 'decimal:1',
        ];
    }

    /** @return HasMany<CurriculumEntry, $this> */
    public function curriculumEntries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }

    /** @param Builder<Subject> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getStage(): string
    {
        return self::TYPE_STAGES[$this->type];
    }
}
