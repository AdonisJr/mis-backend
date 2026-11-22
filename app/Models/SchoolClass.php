<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    public const TRANSACTION_ATTEMPTS = 3;

    public const HOURS_UNDER = 'under';

    public const HOURS_EXACT = 'exact';

    public const HOURS_OVER = 'over';

    public const CLASS_LIST_RELATIONS = ['schoolYear', 'section.gradeLevel', 'section.program', 'curriculumEntry.subject', 'teacher', 'meetings.room'];

    public const WHOLE_YEAR_QUARTERS = [1, 2, 3, 4];

    public const QUARTERS_PER_TERM = 2;

    public const QUARTER_SUBMITTED_MESSAGE = 'The quarter is already submitted.';

    public const QUARTER_NOT_SUBMITTED_MESSAGE = 'Only a submitted quarter can be reopened.';

    public const RESOURCE_RELATIONS = ['section', 'curriculumEntry.subject', 'teacher', 'meetings.room'];

    private const MINUTES_PER_HOUR = 60;

    // the model cannot be named Class (reserved word); the table keeps the domain name
    protected $table = 'classes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['section_id', 'curriculum_entry_id', 'teacher_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section_id' => 'integer',
            'curriculum_entry_id' => 'integer',
            'teacher_id' => 'integer',
            'school_year_id' => 'integer',
            'term_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<CurriculumEntry, $this> */
    public function curriculumEntry(): BelongsTo
    {
        return $this->belongsTo(CurriculumEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return HasMany<ClassMeeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(ClassMeeting::class, 'class_id')->orderBy('day')->orderBy('start_minutes')->orderBy('id');
    }

    /** @return HasMany<GradingActivity, $this> */
    public function gradingActivities(): HasMany
    {
        return $this->hasMany(GradingActivity::class, 'class_id');
    }

    /** @return HasMany<ClassQuarter, $this> */
    public function classQuarters(): HasMany
    {
        return $this->hasMany(ClassQuarter::class, 'class_id');
    }

    /** @return list<int> */
    public static function listQuartersForTerm(?int $termNumber): array
    {
        if ($termNumber === null) {
            return self::WHOLE_YEAR_QUARTERS;
        }

        $lastQuarter = $termNumber * self::QUARTERS_PER_TERM;

        return [$lastQuarter - 1, $lastQuarter];
    }

    /** @return list<int> */
    public function listGradableQuarters(): array
    {
        return self::listQuartersForTerm($this->term_number);
    }

    public function isInTrimesterYear(): bool
    {
        return $this->schoolYear->term_type === SchoolYear::TERM_TYPE_TRIMESTER;
    }

    public function findClassQuarter(int $quarter): ?ClassQuarter
    {
        return $this->classQuarters()->where('quarter', $quarter)->first();
    }

    public function isQuarterSubmitted(int $quarter): bool
    {
        return (bool) $this->findClassQuarter($quarter)?->isSubmitted();
    }

    public function hasQaActivity(int $quarter, ?int $exceptActivityId): bool
    {
        return $this->gradingActivities()
            ->where('quarter', $quarter)
            ->where('component', GradingActivity::COMPONENT_QA)
            ->when($exceptActivityId, fn ($activityQuery) => $activityQuery->where('id', '!=', $exceptActivityId))
            ->exists();
    }

    /** @throws ValidationException */
    public function lockOpenQuarter(int $quarter): void
    {
        // every grading write of a class serializes on its row, so a submit cannot slip between a write's check and its insert
        self::whereKey($this->id)->lockForUpdate()->first();

        if ($this->isQuarterSubmitted($quarter)) {
            throw ValidationException::withMessages(['quarter' => self::QUARTER_SUBMITTED_MESSAGE]);
        }
    }

    /** @throws ValidationException */
    public function lockSubmittedQuarter(int $quarter): ClassQuarter
    {
        self::whereKey($this->id)->lockForUpdate()->first();
        $classQuarter = $this->findClassQuarter($quarter);

        if (! $classQuarter?->isSubmitted()) {
            throw ValidationException::withMessages(['quarter' => self::QUARTER_NOT_SUBMITTED_MESSAGE]);
        }

        return $classQuarter;
    }

    public function countScheduledMinutes(): int
    {
        return $this->meetings->sum(fn (ClassMeeting $meeting) => $meeting->countMinutes());
    }

    public function countRequiredMinutes(): int
    {
        // weekly hours are 0.5 steps, so the minute count is always whole
        return BigDecimal::of($this->curriculumEntry->subject->weekly_hours)->multipliedBy(self::MINUTES_PER_HOUR)->toInt();
    }

    public function deriveHoursStatus(): string
    {
        $scheduledMinutes = $this->countScheduledMinutes();
        $requiredMinutes = $this->countRequiredMinutes();

        return match (true) {
            $scheduledMinutes < $requiredMinutes => self::HOURS_UNDER,
            $scheduledMinutes === $requiredMinutes => self::HOURS_EXACT,
            default => self::HOURS_OVER,
        };
    }
}
