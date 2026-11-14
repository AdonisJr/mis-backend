<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyPaymentPlansRequest;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentPlanCopyController extends Controller
{
    public const SKIP_NAME_EXISTS_REASON = 'A payment plan with this name already exists in the target school year.';

    public const SKIP_DATE_OUTSIDE_REASON = 'A moved due date falls outside the target school year.';

    public function copyPaymentPlans(CopyPaymentPlansRequest $request): JsonResponse
    {
        $copyOutcome = DB::transaction(fn () => $this->copyWithinLock($request), PaymentPlan::TRANSACTION_ATTEMPTS);

        return response()->json($copyOutcome);
    }

    private function copyWithinLock(CopyPaymentPlansRequest $request): array
    {
        // the same year row lock as a create, so a concurrent create cannot take a name between the check and the insert
        $targetYear = SchoolYear::whereKey($request->validated('target_school_year_id'))->lockForUpdate()->firstOrFail();
        $sourceYear = SchoolYear::findOrFail($request->validated('source_school_year_id'));
        $yearOffset = $targetYear->start_date->year - $sourceYear->start_date->year;
        $sourcePlans = $sourceYear->paymentPlans()->with(PaymentPlanController::RELATIONS)->orderBy('name')->orderBy('id')->get();
        $copiedCount = 0;
        $skippedRows = [];

        foreach ($sourcePlans as $sourcePlan) {
            $copyCandidate = $this->buildCopyCandidate($sourcePlan, $targetYear);
            $shiftedDueDates = $this->shiftDueDates($sourcePlan, $yearOffset);
            $skipReason = $this->findSkipReason($copyCandidate, $shiftedDueDates);

            if ($skipReason !== null) {
                $skippedRows[] = ['name' => $sourcePlan->name, 'reason' => $skipReason];

                continue;
            }

            $this->saveCopy($copyCandidate, $shiftedDueDates);
            $copiedCount++;
        }

        return ['copied_count' => $copiedCount, 'skipped' => $skippedRows];
    }

    private function buildCopyCandidate(PaymentPlan $sourcePlan, SchoolYear $targetYear): PaymentPlan
    {
        // replicate keeps is_active, the down payment and the adjustment, and drops the id and timestamps
        $copyCandidate = $sourcePlan->replicate();
        $copyCandidate->school_year_id = $targetYear->id;
        $copyCandidate->setRelation('schoolYear', $targetYear);

        return $copyCandidate;
    }

    /** @return Collection<int, string> */
    private function shiftDueDates(PaymentPlan $sourcePlan, int $yearOffset): Collection
    {
        return $sourcePlan->installments->map(fn (PaymentPlanInstallment $installment) => PaymentPlanInstallment::shiftDueDate($installment->due_date->toDateString(), $yearOffset));
    }

    /** @param Collection<int, string> $shiftedDueDates */
    private function findSkipReason(PaymentPlan $copyCandidate, Collection $shiftedDueDates): ?string
    {
        return match (true) {
            PaymentPlan::withSameName($copyCandidate)->exists() => self::SKIP_NAME_EXISTS_REASON,
            $shiftedDueDates->contains(fn (string $dueDate) => ! $copyCandidate->schoolYear->containsDate($dueDate)) => self::SKIP_DATE_OUTSIDE_REASON,
            default => null,
        };
    }

    /** @param Collection<int, string> $shiftedDueDates */
    private function saveCopy(PaymentPlan $copyCandidate, Collection $shiftedDueDates): void
    {
        $copyCandidate->save();
        $copyCandidate->installments()->createMany($shiftedDueDates->values()->map(fn (string $dueDate, int $dueDateIndex) => ['sequence' => $dueDateIndex + 1, 'due_date' => $dueDate])->all());
    }
}
