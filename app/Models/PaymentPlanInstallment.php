<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentPlanInstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPlanInstallment extends Model
{
    /** @use HasFactory<PaymentPlanInstallmentFactory> */
    use HasFactory;

    private const DATE_LENGTH = 10;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'payment_plan_id',
        'sequence',
        'due_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_plan_id' => 'integer',
            'sequence' => 'integer',
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public static function shiftDueDate(string $dueDate, int $yearOffset): string
    {
        // no-overflow shifting keeps Feb 29 in February (Feb 28 when the target year has none), never March 1
        return CarbonImmutable::parse($dueDate)->addYearsNoOverflow($yearOffset)->toDateString();
    }

    /** @return array{earliest: string, latest: string}|null */
    public static function findDueDateBounds(int $schoolYearId): ?array
    {
        $bounds = self::query()
            ->whereHas('paymentPlan', fn ($planQuery) => $planQuery->where('school_year_id', $schoolYearId))
            ->toBase()
            ->selectRaw('MIN(due_date) as earliest, MAX(due_date) as latest')
            ->first();

        if ($bounds === null || $bounds->earliest === null) {
            return null;
        }

        // SQLite stores date casts with a time part, so the values are cut to Y-m-d to compare with request input
        return [
            'earliest' => substr($bounds->earliest, 0, self::DATE_LENGTH),
            'latest' => substr($bounds->latest, 0, self::DATE_LENGTH),
        ];
    }
}
