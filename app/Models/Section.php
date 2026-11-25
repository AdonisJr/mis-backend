<?php

namespace App\Models;

use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_year_id',
        'grade_level_id',
        'program_id',
        'name',
        'capacity',
        'room_id',
        'adviser_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids and capacity as digit strings; the casts keep the JSON numeric
        return [
            'school_year_id' => 'integer',
            'grade_level_id' => 'integer',
            'program_id' => 'integer',
            'capacity' => 'integer',
            'room_id' => 'integer',
            'adviser_id' => 'integer',
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

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return Collection<int, Student> */
    public function fetchListedStudents(): Collection
    {
        return Student::query()
            ->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery->where('section_id', $this->id)->whereIn('status', Enrollment::LISTED_STATUSES))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
    }

    public function countMostSeatsTaken(): int
    {
        $seatCounts = $this->enrollments()->enrolled()->toBase()->selectRaw('COUNT(*) AS seats_taken')->groupBy('term_id')->pluck('seats_taken');

        return (int) ($seatCounts->max() ?? 0);
    }

    /** @return HasMany<SchoolClass, $this> */
    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /** @return Collection<int, CurriculumEntry> */
    public function fetchTimetableEntries(?int $termNumber): Collection
    {
        return CurriculumEntry::query()
            ->orderedForList()
            ->where('curriculum_entries.school_year_id', $this->school_year_id)
            ->where('curriculum_entries.grade_level_id', $this->grade_level_id)
            ->where('curriculum_entries.program_id', $this->program_id)
            ->where('curriculum_entries.term_number', $termNumber)
            ->with([
                'subject',
                'schoolClasses' => fn ($classQuery) => $classQuery->where('section_id', $this->id)->with(SchoolClass::RESOURCE_RELATIONS),
            ])
            ->get();
    }

    /** @return array<string, \Closure(Builder): mixed> */
    public static function buildEnrolledCount(?int $termId): array
    {
        // a JHS section has no term, so its whole-year rows count for any term; without a term every enrolled row counts
        return ['enrollments as enrolled_count' => fn (Builder $enrollmentQuery) => $enrollmentQuery
            ->enrolled()
            ->when($termId, fn (Builder $countQuery) => $countQuery->where(fn (Builder $termQuery) => $termQuery->whereNull('enrollments.term_id')->orWhere('enrollments.term_id', $termId))),
        ];
    }
}
