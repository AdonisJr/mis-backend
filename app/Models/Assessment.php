<?php

namespace App\Models;

use App\Support\PaymentAllocation;
use Brick\Math\BigDecimal;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [self::STATUS_CURRENT, self::STATUS_SUPERSEDED];

    public const TRANSACTION_ATTEMPTS = 3;

    public const DETAIL_RELATIONS = ['student', 'schoolYear', 'gradeLevel', 'program', 'creator', 'superseder', 'feeLines', 'discounts', 'installments', 'payments'];

    public const LIST_RELATIONS = ['student', 'gradeLevel', 'program', 'creator', 'superseder'];

    public const LEDGER_RELATIONS = ['student', 'schoolYear', 'gradeLevel', 'program', 'installments', 'payments.student', 'payments.recorder', 'payments.voider'];

    public const HAS_PAYMENTS_MESSAGE = 'The assessment has payments. Void them before re-assessing.';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'school_year_id',
        'payment_plan_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // form input may send ids as digit strings; the casts keep the JSON numeric and the money as strings
        return [
            'student_id' => 'integer',
            'school_year_id' => 'integer',
            'grade_level_id' => 'integer',
            'program_id' => 'integer',
            'payment_plan_id' => 'integer',
            'adjustment_value' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'gross_tuition' => 'decimal:2',
            'gross_miscellaneous' => 'decimal:2',
            'gross_other' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'discount_tuition' => 'decimal:2',
            'discount_miscellaneous' => 'decimal:2',
            'discount_other' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'superseded_at' => 'datetime',
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

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function superseder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    /** @return HasMany<AssessmentFeeLine, $this> */
    public function feeLines(): HasMany
    {
        return $this->hasMany(AssessmentFeeLine::class)->orderBy('id');
    }

    /** @return HasMany<AssessmentDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(AssessmentDiscount::class)->orderBy('id');
    }

    /** @return HasMany<AssessmentInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(AssessmentInstallment::class)->orderBy('sequence');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    /** Fresh query, so it stays correct under the student-row lock regardless of loaded relations. */
    public function hasValidPayments(): bool
    {
        return $this->payments()->valid()->exists();
    }

    public function calculateTotalPaid(): BigDecimal
    {
        return PaymentAllocation::sumValidAmounts($this->payments);
    }

    public function calculateBalance(): BigDecimal
    {
        return BigDecimal::of($this->net_total)->minus($this->calculateTotalPaid());
    }

    /** Notes of this student and year, each carrying this assessment's balance so its status can be derived. */
    public function loadPromissoryNotes(): self
    {
        $promissoryNotes = $this->student->promissoryNotes()
            ->where('promissory_notes.school_year_id', $this->school_year_id)
            ->with(PromissoryNote::ROW_RELATIONS)
            ->get();
        $promissoryNotes->each->assignYearBalance($this->calculateBalance());

        return $this->setRelation('promissoryNotes', $promissoryNotes);
    }

    /** @param Builder<Assessment> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('assessments.status', self::STATUS_CURRENT);
    }

    /** @param Builder<Assessment> $query */
    public function scopeSameStudentYear(Builder $query, Assessment|StudentDiscount $keyed): void
    {
        $query->where('assessments.student_id', $keyed->student_id)->where('assessments.school_year_id', $keyed->school_year_id);
    }

    /**
     * The enrollment that decides the grade level and program: billable stage only, enrolled before completed, newest first.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     */
    public static function pickSourceEnrollment(Collection $enrollments): ?Enrollment
    {
        return $enrollments
            ->filter(fn (Enrollment $enrollment) => in_array($enrollment->section->gradeLevel->stage, FeeSchedule::BILLABLE_STAGES, true))
            ->sort(fn (Enrollment $first, Enrollment $second) => [$second->status === Enrollment::STATUS_ENROLLED, $second->id] <=> [$first->status === Enrollment::STATUS_ENROLLED, $first->id])
            ->first();
    }

    public function buildReassessCandidate(int $paymentPlanId): self
    {
        return new self([
            'student_id' => $this->student_id,
            'school_year_id' => $this->school_year_id,
            'payment_plan_id' => $paymentPlanId,
        ]);
    }

    /**
     * Re-read the plan under a row lock so a create cannot point at a plan that a delete is removing.
     *
     * @throws ValidationException
     */
    public function lockPaymentPlan(): void
    {
        $paymentPlan = PaymentPlan::whereKey($this->payment_plan_id)->lockForUpdate()->first();

        if (! $paymentPlan) {
            throw ValidationException::withMessages(['payment_plan_id' => __('validation.exists', ['attribute' => 'payment plan id'])]);
        }

        $this->setRelation('paymentPlan', $paymentPlan);
    }

    /** Saves inside the caller's transaction; the previous current row, when given, is superseded first. */
    public function saveReplacing(?self $previous): self
    {
        $previous?->forceFill(['status' => self::STATUS_SUPERSEDED, 'superseded_at' => now(), 'superseded_by' => $this->created_by])->save();

        $feeLines = $this->feeLines;
        $discounts = $this->discounts;
        $installments = $this->installments;
        $this->save();
        $this->feeLines()->saveMany($feeLines);
        $this->discounts()->saveMany($discounts);
        $this->installments()->saveMany($installments);

        return $this->load(self::DETAIL_RELATIONS);
    }
}
