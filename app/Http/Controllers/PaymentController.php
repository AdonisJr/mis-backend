<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListPaymentsRequest;
use App\Http\Requests\ShowPaymentLedgerRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Http\Resources\PaymentLedgerResource;
use App\Http\Resources\PaymentReceiptResource;
use App\Http\Resources\PaymentResource;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\PaymentAllocation;
use App\Support\ReceiptNumberAllocator;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    use SearchesPaginatedLists;

    public const SUPERSEDED_MESSAGE = 'Payments can only be recorded on the current assessment.';

    public const OVERPAYMENT_MESSAGE = 'The amount exceeds the remaining balance.';

    public const ALREADY_VOIDED_MESSAGE = 'The payment is already voided.';

    public const VOID_NOT_ALLOWED_MESSAGE = 'Cashiers can only void their own payments on the day they were recorded.';

    public const NO_CURRENT_ASSESSMENT_MESSAGE = 'The student has no current assessment for this school year.';

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name', 'payments.ar_number'];

    private const MONEY_SCALE = 2;

    public function listPayments(ListPaymentsRequest $request): JsonResponse
    {
        $listQuery = $this->buildListQuery($request);
        $paymentPage = (clone $listQuery)->paginate();

        return response()->json([
            'payments' => PaymentResource::collection($paymentPage->getCollection()),
            'meta' => $this->buildPageMeta($paymentPage),
            'collection_total' => $this->sumCollection($listQuery),
        ]);
    }

    public function recordPayment(StorePaymentRequest $request): JsonResponse
    {
        $payment = DB::transaction(fn () => $this->recordWithinLock($request), Payment::TRANSACTION_ATTEMPTS);

        return response()->json([
            'payment' => PaymentResource::make($payment),
        ], Response::HTTP_CREATED);
    }

    public function voidPayment(VoidPaymentRequest $request, int $id): JsonResponse
    {
        $storedPayment = Payment::findOrFail($id);

        if (! $storedPayment->isVoidPermittedFor($request->user())) {
            return response()->json(['message' => self::VOID_NOT_ALLOWED_MESSAGE], Response::HTTP_FORBIDDEN);
        }

        $payment = DB::transaction(fn () => $this->voidWithinLock($request, $storedPayment), Payment::TRANSACTION_ATTEMPTS);

        return response()->json([
            'payment' => PaymentResource::make($payment),
        ]);
    }

    public function showReceipt(int $id): JsonResponse
    {
        return response()->json([
            'receipt' => PaymentReceiptResource::make(Payment::with(Payment::RECEIPT_RELATIONS)->findOrFail($id)),
        ]);
    }

    public function showLedger(ShowPaymentLedgerRequest $request): JsonResponse
    {
        $currentAssessment = Assessment::with(Assessment::LEDGER_RELATIONS)
            ->current()
            ->where('assessments.student_id', $request->validated('student_id'))
            ->where('assessments.school_year_id', $request->validated('school_year_id'))
            ->first();

        if ($currentAssessment === null) {
            return response()->json(['message' => self::NO_CURRENT_ASSESSMENT_MESSAGE], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'ledger' => PaymentLedgerResource::make($currentAssessment),
        ]);
    }

    public function listRecorders(): JsonResponse
    {
        // active or not: a deactivated cashier's past payments must stay filterable
        $recorders = User::whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', Role::PAYMENT_WRITE_ROLES))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        return response()->json([
            'recorders' => $recorders->map(fn (User $recorder) => ['id' => $recorder->id, 'name' => $recorder->name])->all(),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildListQuery(ListPaymentsRequest $request): Builder
    {
        $listQuery = Payment::query()
            // select only payment columns: the joined students table also has id and timestamps
            ->select('payments.*')
            ->join('students', 'students.id', '=', 'payments.student_id')
            ->with(Payment::ROW_RELATIONS)
            // whereDate: the stored date may carry a time part, which would drop the inclusive end day
            ->whereDate('payments.payment_date', '>=', $request->validated('date_from'))
            ->whereDate('payments.payment_date', '<=', $request->validated('date_to'))
            ->when($request->filled('method'), fn (Builder $query) => $query->where('payments.method', $request->validated('method')))
            ->when($request->filled('recorded_by'), fn (Builder $query) => $query->where('payments.recorded_by', $request->validated('recorded_by')))
            ->when($request->validated('status') === Payment::STATUS_VALID, fn (Builder $query) => $query->valid())
            ->when($request->validated('status') === Payment::STATUS_VOIDED, fn (Builder $query) => $query->voided())
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id');
        $this->applySearch($listQuery, (string) $request->validated('search'));

        return $listQuery;
    }

    private function sumCollection(Builder $listQuery): string
    {
        // summed in PHP: SQLite SUM on decimal columns returns a float
        $validAmounts = (clone $listQuery)->valid()->reorder()->pluck('payments.amount');

        return (string) PaymentAllocation::sumAmounts($validAmounts);
    }

    private function recordWithinLock(StorePaymentRequest $request): Payment
    {
        $assessment = $this->lockCurrentAssessment((int) $request->validated('assessment_id'));
        $assessment->load('payments');
        $amount = BigDecimal::of((string) $request->validated('amount'))->toScale(self::MONEY_SCALE);
        $balance = $assessment->calculateBalance();

        if ($amount->isGreaterThan($balance)) {
            throw ValidationException::withMessages(['amount' => self::OVERPAYMENT_MESSAGE]);
        }

        $payment = new Payment($request->safe()->only(['method', 'reference_no', 'remarks']));
        $payment->forceFill([
            'assessment_id' => $assessment->id,
            'student_id' => $assessment->student_id,
            'school_year_id' => $assessment->school_year_id,
            'payment_date' => $request->validated('payment_date') ?? today()->toDateString(),
            // allocated after the balance check so a rejected payment never reaches the counter
            'ar_number' => ReceiptNumberAllocator::allocateNext(),
            'amount' => (string) $amount,
            'balance_after' => (string) $balance->minus($amount)->toScale(self::MONEY_SCALE),
            'recorded_by' => $request->user()->id,
        ])->save();

        return $payment->load(Payment::ROW_RELATIONS);
    }

    private function lockCurrentAssessment(int $assessmentId): Assessment
    {
        // lock order student -> assessment -> counter, shared with re-assess, so the paths cannot deadlock
        $studentId = Assessment::whereKey($assessmentId)->value('student_id');
        Student::whereKey($studentId)->lockForUpdate()->first();
        // re-read under the lock: another tab may have re-assessed meanwhile
        $assessment = Assessment::lockForUpdate()->findOrFail($assessmentId);

        if ($assessment->status === Assessment::STATUS_SUPERSEDED) {
            throw ValidationException::withMessages(['assessment_id' => self::SUPERSEDED_MESSAGE]);
        }

        return $assessment;
    }

    private function voidWithinLock(VoidPaymentRequest $request, Payment $storedPayment): Payment
    {
        // re-read under the lock: a second tab may have voided this row meanwhile
        $payment = Payment::lockForUpdate()->findOrFail($storedPayment->id);

        if ($payment->isVoided()) {
            throw ValidationException::withMessages(['payment' => self::ALREADY_VOIDED_MESSAGE]);
        }

        $payment->forceFill([
            'voided_at' => now(),
            'voided_by' => $request->user()->id,
            'void_reason' => $request->validated('void_reason'),
        ])->save();

        return $payment->load(Payment::ROW_RELATIONS);
    }
}
