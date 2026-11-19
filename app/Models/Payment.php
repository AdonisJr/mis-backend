<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const METHOD_CASH = 'cash';

    public const METHOD_E_WALLET = 'e_wallet';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CHECK = 'check';

    public const METHODS = [self::METHOD_CASH, self::METHOD_E_WALLET, self::METHOD_BANK_TRANSFER, self::METHOD_CHECK];

    public const STATUS_VALID = 'valid';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [self::STATUS_VALID, self::STATUS_VOIDED];

    public const AR_NUMBER_FORMAT = 'AR-%06d';

    public const TRANSACTION_ATTEMPTS = 3;

    public const ROW_RELATIONS = ['student', 'recorder', 'voider'];

    public const RECEIPT_RELATIONS = [...self::ROW_RELATIONS, 'schoolYear', 'assessment.gradeLevel', 'assessment.program'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'method',
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
        return [
            'assessment_id' => 'integer',
            'student_id' => 'integer',
            'school_year_id' => 'integer',
            'recorded_by' => 'integer',
            'voided_by' => 'integer',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payment_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
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

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @param Builder<Payment> $query */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('payments.voided_at');
    }

    /** @param Builder<Payment> $query */
    public function scopeVoided(Builder $query): void
    {
        $query->whereNotNull('payments.voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Admin: any payment. Cashier: only the payments they recorded on the same Manila day. */
    public function isVoidPermittedFor(User $user): bool
    {
        if ($user->hasRole(Role::ADMIN)) {
            return true;
        }

        if (! $user->hasRole(Role::CASHIER)) {
            return false;
        }

        return $this->recorded_by === $user->id && $this->created_at->isToday();
    }

    public function canBeVoidedBy(User $user): bool
    {
        return ! $this->isVoided() && $this->isVoidPermittedFor($user);
    }
}
