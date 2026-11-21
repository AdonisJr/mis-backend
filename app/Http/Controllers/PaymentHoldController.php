<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\CheckPaymentHoldRequest;
use App\Http\Requests\ListPaymentHoldsRequest;
use App\Http\Resources\PaymentHoldResource;
use App\Http\Resources\PaymentHoldRowResource;
use App\Models\Assessment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Support\PaymentHold;
use App\Support\PaymentHoldList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class PaymentHoldController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    public function checkHold(CheckPaymentHoldRequest $request): JsonResponse
    {
        $targetYear = SchoolYear::findOrFail($request->validated('school_year_id'));

        return response()->json([
            'hold' => PaymentHoldResource::make(new PaymentHold((int) $request->validated('student_id'), $targetYear)),
        ]);
    }

    public function listPaymentHolds(ListPaymentHoldsRequest $request): JsonResponse
    {
        $holdList = new PaymentHoldList($this->queryListedAssessments($request), $this->queryListedNotes($request));
        $holdPage = $holdList->paginateRows($request->validated('status'), (int) ($request->validated('page') ?? 1));

        return response()->json([
            'payment_holds' => PaymentHoldRowResource::collection($holdPage->getCollection()),
            'meta' => $this->buildPageMeta($holdPage),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function applyListFilters(Builder $listQuery, ListPaymentHoldsRequest $request): Builder
    {
        $table = $listQuery->getModel()->getTable();
        $listQuery
            ->join('students', 'students.id', '=', $table.'.student_id')
            ->when($request->filled('school_year_id'), fn (Builder $query) => $query->where($table.'.school_year_id', $request->validated('school_year_id')));
        $this->applySearch($listQuery, (string) $request->validated('search'));

        return $listQuery;
    }

    private function queryListedAssessments(ListPaymentHoldsRequest $request): Collection
    {
        return $this->applyListFilters(Assessment::current()->select('assessments.*'), $request)
            ->with(['student', 'schoolYear', 'payments'])
            ->get();
    }

    private function queryListedNotes(ListPaymentHoldsRequest $request): Collection
    {
        return $this->applyListFilters(PromissoryNote::query()->select('promissory_notes.*'), $request)
            ->with(['student', 'schoolYear', 'granter'])
            ->orderByDesc('promissory_notes.id')
            ->get();
    }
}
