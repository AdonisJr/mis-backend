<?php

namespace App\Models;

use Database\Factories\CurriculumEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumEntry extends Model
{
    /** @use HasFactory<CurriculumEntryFactory> */
    use HasFactory;

    public const CURRICULUM_STAGES = [GradeLevel::STAGE_JHS, GradeLevel::STAGE_SHS];

    public const KEY_FIELDS = ['school_year_id', 'grade_level_id', 'subject_id', 'program_id', 'term_number'];

    public const TRANSACTION_ATTEMPTS = 3;

    public const SORT_ORDER_MAX = 9999;

    private const NO_VALUE_KEY = 'none';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [...self::KEY_FIELDS, 'sort_order'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send numbers as digit strings; the casts keep the JSON numeric
        return [
            'school_year_id' => 'integer',
            'grade_level_id' => 'integer',
            'subject_id' => 'integer',
            'program_id' => 'integer',
            'term_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @param Builder<CurriculumEntry> $query */
    public function scopeWithSameKey(Builder $query, CurriculumEntry $candidate): void
    {
        // a null program_id or term_number compiles to IS NULL, so a missing value is its own key value
        foreach (self::KEY_FIELDS as $keyField) {
            $query->where($keyField, $candidate->{$keyField});
        }
    }

    /** @param Builder<CurriculumEntry> $query */
    public function scopeOrderedForList(Builder $query): void
    {
        // select only entry columns: the joined tables also have id and timestamps
        $query->select('curriculum_entries.*')
            ->join('grade_levels', 'grade_levels.id', '=', 'curriculum_entries.grade_level_id')
            ->leftJoin('programs', 'programs.id', '=', 'curriculum_entries.program_id')
            ->orderBy('grade_levels.sort_order')
            ->orderByRaw('CASE WHEN curriculum_entries.program_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('programs.code')
            ->orderBy('curriculum_entries.term_number')
            ->orderByRaw('CASE WHEN curriculum_entries.sort_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('curriculum_entries.sort_order')
            ->orderBy('curriculum_entries.id');
    }

    public function buildGroupKey(): string
    {
        return $this->grade_level_id.'-'.($this->program_id ?? self::NO_VALUE_KEY).'-'.($this->term_number ?? self::NO_VALUE_KEY);
    }
}
