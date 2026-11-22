<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    public const TRANSACTION_ATTEMPTS = 3;

    public const HOURS_UNDER = 'under';

    public const HOURS_EXACT = 'exact';

    public const HOURS_OVER = 'over';

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
