<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    public const STATUS_ENROLLED = 'enrolled';

    public const STATUS_MOVED = 'moved';

    public const STATUS_DROPPED = 'dropped';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_ENROLLED,
        self::STATUS_MOVED,
        self::STATUS_DROPPED,
        self::STATUS_TRANSFERRED,
        self::STATUS_COMPLETED,
    ];

    public const CLOSED_MESSAGE = 'Only an enrolled record can be changed.';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'section_id',
        'term_id',
        'status',
        'enrolled_at',
        'ended_at',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids as digit strings; the casts keep the JSON numeric
        return [
            'student_id' => 'integer',
            'section_id' => 'integer',
            'term_id' => 'integer',
            'enrolled_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function scopeEnrolled(Builder $query): void
    {
        // table-qualified because the enrollment list joins other tables
        $query->where('enrollments.status', self::STATUS_ENROLLED);
    }

    public function scopeCurrent(Builder $query): void
    {
        $activeSchoolYearId = SchoolYear::where('is_active', true)->value('id');
        $activeTermId = Term::where('is_active', true)->value('id');

        $query->enrolled()
            // no active year compiles to IS NULL, which matches nothing, so the student shows as not enrolled
            ->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('school_year_id', $activeSchoolYearId))
            ->when($activeTermId, fn (Builder $currentQuery) => $currentQuery->where(fn (Builder $termQuery) => $termQuery->whereNull('term_id')->orWhere('term_id', $activeTermId)));
    }

    public function countSeatsTaken(): int
    {
        // a null term_id compiles to IS NULL, so a JHS section counts its whole-year rows and an SHS or college section counts one term
        return self::enrolled()->where('section_id', $this->section_id)->where('term_id', $this->term_id)->count();
    }

    public function hasOverlappingEnrollment(): bool
    {
        return self::enrolled()
            ->where('student_id', $this->student_id)
            ->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('school_year_id', $this->section->school_year_id))
            ->when($this->term_id, fn (Builder $overlapQuery) => $overlapQuery->where(fn (Builder $termQuery) => $termQuery->whereNull('term_id')->orWhere('term_id', $this->term_id)))
            ->exists();
    }
}
