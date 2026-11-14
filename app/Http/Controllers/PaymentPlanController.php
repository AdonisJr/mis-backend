<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListPaymentPlansRequest;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\StorePaymentPlanRequest;
use App\Http\Requests\UpdatePaymentPlanRequest;
use App\Http\Resources\PaymentPlanResource;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PaymentPlanController extends Controller
{
    use SearchesPaginatedLists;

    public const RELATIONS = ['installments'];

    private const SEARCH_COLUMNS = ['name'];

    public function listPaymentPlans(ListPaymentPlansRequest $request): JsonResponse
    {
        $paymentPlanQuery = PaymentPlan::query()->with(self::RELATIONS)->where('school_year_id', $request->validated('school_year_id'))->orderBy('name')->orderBy('id');
        $this->applySearch($paymentPlanQuery, (string) $request->validated('search'));
        $paymentPlanQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $paymentPlanPage = $paymentPlanQuery->paginate();

        return response()->json([
            'payment_plans' => PaymentPlanResource::collection($paymentPlanPage->getCollection()),
            'meta' => $this->buildPageMeta($paymentPlanPage),
        ]);
    }

    public function showPaymentPlan(int $id): JsonResponse
    {
        return response()->json([
            'payment_plan' => PaymentPlanResource::make(PaymentPlan::with(self::RELATIONS)->findOrFail($id)),
        ]);
    }

    public function createPaymentPlan(StorePaymentPlanRequest $request): JsonResponse
    {
        $paymentPlan = DB::transaction(fn () => $this->createWithinLock($request), PaymentPlan::TRANSACTION_ATTEMPTS);

        return response()->json([
            'payment_plan' => PaymentPlanResource::make($paymentPlan),
        ], Response::HTTP_CREATED);
    }

    public function updatePaymentPlan(UpdatePaymentPlanRequest $request, int $id): JsonResponse
    {
        $storedPlan = PaymentPlan::findOrFail($id);
        $paymentPlan = DB::transaction(fn () => $this->updateWithinLock($request, $storedPlan), PaymentPlan::TRANSACTION_ATTEMPTS);

        return response()->json([
            'payment_plan' => PaymentPlanResource::make($paymentPlan),
        ]);
    }

    public function deactivatePaymentPlan(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivatePaymentPlan(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    public function deletePaymentPlan(int $id): Response
    {
        PaymentPlan::findOrFail($id)->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $paymentPlan = PaymentPlan::with(self::RELATIONS)->findOrFail($id);
        $paymentPlan->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'payment_plan' => PaymentPlanResource::make($paymentPlan),
        ]);
    }

    private function createWithinLock(StorePaymentPlanRequest $request): PaymentPlan
    {
        $candidate = $request->buildNameCandidate();
        $this->rejectTakenName($candidate);

        $paymentPlan = PaymentPlan::create([...$request->buildPlanAttributes(), 'school_year_id' => $candidate->school_year_id]);
        $paymentPlan->installments()->createMany($request->buildDueDateRows());

        return $paymentPlan->load(self::RELATIONS);
    }

    private function updateWithinLock(UpdatePaymentPlanRequest $request, PaymentPlan $storedPlan): PaymentPlan
    {
        $this->rejectTakenName($request->buildNameCandidate());

        $paymentPlan = PaymentPlan::lockForUpdate()->findOrFail($storedPlan->id);
        $paymentPlan->update($request->buildPlanAttributes());
        PaymentPlanInstallment::where('payment_plan_id', $paymentPlan->id)->delete();
        $paymentPlan->installments()->createMany($request->buildDueDateRows());
        $paymentPlan->touch();

        return $paymentPlan->load(self::RELATIONS);
    }

    private function rejectTakenName(PaymentPlan $candidate): void
    {
        // every write for a school year serializes on this row, so two requests cannot both pass the name check
        SchoolYear::whereKey($candidate->school_year_id)->lockForUpdate()->first();

        if (PaymentPlan::withSameName($candidate)->exists()) {
            throw ValidationException::withMessages(['name' => StorePaymentPlanRequest::NAME_TAKEN_MESSAGE]);
        }
    }
}
