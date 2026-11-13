<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\FeeScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeSchedule extends Model
{
    /** @use HasFactory<FeeScheduleFactory> */
    use HasFactory;

    public const BILLABLE_STAGES = [GradeLevel::STAGE_JHS, GradeLevel::STAGE_SHS];

    public const KEY_FIELDS = ['school_year_id', 'grade_level_id', 'program_id'];

    public const TRANSACTION_ATTEMPTS = 3;

    private const MONEY_SCALE = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = self::KEY_FIELDS;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids as digit strings; the casts keep the JSON numeric
        return [
            'school_year_id' => 'integer',
            'grade_level_id' => 'integer',
            'program_id' => 'integer',
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

    /** @return HasMany<FeeScheduleLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FeeScheduleLine::class)->orderBy('id');
    }

    /** @param Builder<FeeSchedule> $query */
    public function scopeWithSameKey(Builder $query, FeeSchedule $candidate): void
    {
        // a null program_id compiles to IS NULL, so the grade level default is its own value
        $query->where('school_year_id', $candidate->school_year_id)
            ->where('grade_level_id', $candidate->grade_level_id)
            ->where('program_id', $candidate->program_id);
    }

    /** @param Builder<FeeSchedule> $query */
    public function scopeOrderedForList(Builder $query): void
    {
        // select only schedule columns: the joined tables also have id and timestamps
        $query->select('fee_schedules.*')
            ->join('grade_levels', 'grade_levels.id', '=', 'fee_schedules.grade_level_id')
            ->leftJoin('programs', 'programs.id', '=', 'fee_schedules.program_id')
            ->orderBy('grade_levels.sort_order')
            ->orderByRaw('CASE WHEN fee_schedules.program_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('programs.code')
            ->orderBy('fee_schedules.id');
    }

    public function findEffective(): ?FeeSchedule
    {
        return self::query()
            ->where('school_year_id', $this->school_year_id)
            ->where('grade_level_id', $this->grade_level_id)
            ->where(fn (Builder $keyQuery) => $keyQuery->whereNull('program_id')->when($this->program_id, fn (Builder $programQuery) => $programQuery->orWhere('program_id', $this->program_id)))
            ->orderByRaw('CASE WHEN program_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    public function calculateTotal(): string
    {
        $total = BigDecimal::zero();

        foreach ($this->lines as $line) {
            $total = $total->plus($line->amount);
        }

        return (string) $total->toScale(self::MONEY_SCALE);
    }

    /** @return array<string, string> */
    public function calculateSubtotals(): array
    {
        $subtotals = array_fill_keys(FeeItem::CATEGORIES, BigDecimal::zero());

        foreach ($this->lines as $line) {
            $subtotals[$line->feeItem->category] = $subtotals[$line->feeItem->category]->plus($line->amount);
        }

        return array_map(fn (BigDecimal $subtotal) => (string) $subtotal->toScale(self::MONEY_SCALE), $subtotals);
    }
}
