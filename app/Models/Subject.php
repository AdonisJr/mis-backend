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

    public const WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP = 'jhs_languages_ap_esp';

    public const WEIGHT_GROUP_JHS_SCIENCE_MATH = 'jhs_science_math';

    public const WEIGHT_GROUP_JHS_MAPEH_EPP_TLE = 'jhs_mapeh_epp_tle';

    public const WEIGHT_GROUP_SHS_CORE = 'shs_core';

    public const WEIGHT_GROUP_SHS_ACADEMIC_OTHER = 'shs_academic_other';

    public const WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH = 'shs_academic_immersion_research';

    public const WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS = 'shs_tvl_sports_arts';

    public const WEIGHT_GROUPS = [
        self::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP,
        self::WEIGHT_GROUP_JHS_SCIENCE_MATH,
        self::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE,
        self::WEIGHT_GROUP_SHS_CORE,
        self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER,
        self::WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH,
        self::WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS,
    ];

    public const WEIGHT_GROUP_WEIGHTS = [
        self::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP => [GradingActivity::COMPONENT_WW => 30, GradingActivity::COMPONENT_PT => 50, GradingActivity::COMPONENT_QA => 20],
        self::WEIGHT_GROUP_JHS_SCIENCE_MATH => [GradingActivity::COMPONENT_WW => 40, GradingActivity::COMPONENT_PT => 40, GradingActivity::COMPONENT_QA => 20],
        self::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE => [GradingActivity::COMPONENT_WW => 20, GradingActivity::COMPONENT_PT => 60, GradingActivity::COMPONENT_QA => 20],
        self::WEIGHT_GROUP_SHS_CORE => [GradingActivity::COMPONENT_WW => 25, GradingActivity::COMPONENT_PT => 50, GradingActivity::COMPONENT_QA => 25],
        self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER => [GradingActivity::COMPONENT_WW => 25, GradingActivity::COMPONENT_PT => 45, GradingActivity::COMPONENT_QA => 30],
        self::WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH => [GradingActivity::COMPONENT_WW => 35, GradingActivity::COMPONENT_PT => 40, GradingActivity::COMPONENT_QA => 25],
        self::WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS => [GradingActivity::COMPONENT_WW => 20, GradingActivity::COMPONENT_PT => 60, GradingActivity::COMPONENT_QA => 20],
    ];

    public const TYPE_WEIGHT_GROUPS = [
        self::TYPE_JHS_LEARNING_AREA => [self::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP, self::WEIGHT_GROUP_JHS_SCIENCE_MATH, self::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE],
        self::TYPE_SHS_CORE => [self::WEIGHT_GROUP_SHS_CORE],
        self::TYPE_SHS_APPLIED => [self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER, self::WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH, self::WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS],
        self::TYPE_SHS_SPECIALIZED => [self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER, self::WEIGHT_GROUP_SHS_ACADEMIC_IMMERSION_RESEARCH, self::WEIGHT_GROUP_SHS_TVL_SPORTS_ARTS],
    ];

    public const DEFAULT_WEIGHT_GROUPS = [
        self::TYPE_JHS_LEARNING_AREA => self::WEIGHT_GROUP_JHS_LANGUAGES_AP_ESP,
        self::TYPE_SHS_CORE => self::WEIGHT_GROUP_SHS_CORE,
        self::TYPE_SHS_APPLIED => self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER,
        self::TYPE_SHS_SPECIALIZED => self::WEIGHT_GROUP_SHS_ACADEMIC_OTHER,
    ];

    public const WEEKLY_HOURS_MIN = '0.5';

    public const WEEKLY_HOURS_MAX = '40';

    public const WEEKLY_HOURS_STEP = '0.5';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'type', 'weekly_hours', 'description', 'weight_group'];

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

    /** @return array{ww: int, pt: int, qa: int} */
    public function getWeights(): array
    {
        return self::WEIGHT_GROUP_WEIGHTS[$this->weight_group];
    }
}
