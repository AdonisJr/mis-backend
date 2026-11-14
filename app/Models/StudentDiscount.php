<?php

namespace App\Models;

use Database\Factories\StudentDiscountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentDiscount extends Model
{
    /** @use HasFactory<StudentDiscountFactory> */
    use HasFactory;

    public const ELIGIBLE_ENROLLMENT_STATUSES = [Enrollment::STATUS_ENROLLED, Enrollment::STATUS_COMPLETED];

    public const TRANSACTION_ATTEMPTS = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'school_year_id',
        'discount_type_id',
        'reference_no',
        'remarks',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids as digit strings; the casts keep the JSON ids numeric
        return [
            'student_id' => 'integer',
            'school_year_id' => 'integer',
            'discount_type_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolYear, $this> */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /** @return BelongsTo<DiscountType, $this> */
    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function studentEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id', 'student_id')->latest('id');
    }

    /**
     * Stages of the student's enrolled or completed enrollments in the school year.
     *
     * @return list<string>
     */
    public function findEnrolledStages(): array
    {
        // distinct: an SHS student has one row per term, all in the same stage
        return Enrollment::query()
            ->join('sections', 'sections.id', '=', 'enrollments.section_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'sections.grade_level_id')
            ->where('enrollments.student_id', $this->student_id)
            ->where('sections.school_year_id', $this->school_year_id)
            ->whereIn('enrollments.status', self::ELIGIBLE_ENROLLMENT_STATUSES)
            ->distinct()
            ->pluck('grade_levels.stage')
            ->all();
    }

    public function isTypeAlreadyHeld(): bool
    {
        return $this->buildSameStudentYearQuery()->where('discount_type_id', $this->discount_type_id)->exists();
    }

    public function hasOtherGovernmentType(): bool
    {
        if ($this->discountType->source !== DiscountType::SOURCE_GOVERNMENT) {
            return false;
        }

        return $this->buildSameStudentYearQuery()
            ->whereHas('discountType', fn (Builder $typeQuery) => $typeQuery->where('source', DiscountType::SOURCE_GOVERNMENT))
            ->exists();
    }

    /** @return Builder<StudentDiscount> */
    private function buildSameStudentYearQuery(): Builder
    {
        return self::query()->where('student_id', $this->student_id)->where('school_year_id', $this->school_year_id);
    }
}
