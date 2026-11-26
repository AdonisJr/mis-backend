<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ShowArAgingRequest;
use App\Http\Requests\ShowAsOfReportRequest;
use App\Http\Requests\ShowIncomeStatementRequest;
use App\Http\Requests\ShowTrialBalanceRequest;
use App\Models\Assessment;
use App\Support\ArAgingBuilder;
use App\Support\BalanceSheetBuilder;
use App\Support\IncomeStatementBuilder;
use App\Support\TrialBalanceBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class FinancialReportController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    public function showTrialBalance(ShowTrialBalanceRequest $request): JsonResponse
    {
        $trialBalanceBuilder = new TrialBalanceBuilder($request->validated('as_of'));
        $type = $request->filled('type') ? $request->validated('type') : null;

        return response()->json([
            'trial_balance' => $trialBalanceBuilder->build($type, $request->boolean('include_zero')),
        ]);
    }

    public function showIncomeStatement(ShowIncomeStatementRequest $request): JsonResponse
    {
        $statementBuilder = new IncomeStatementBuilder($request->validated('from'), $request->validated('to'));

        return response()->json(['income_statement' => $statementBuilder->build()]);
    }

    public function showBalanceSheet(ShowAsOfReportRequest $request): JsonResponse
    {
        return response()->json(['balance_sheet' => (new BalanceSheetBuilder($request->validated('as_of')))->build()]);
    }

    public function showArAging(ShowArAgingRequest $request): JsonResponse
    {
        $asOf = $request->validated('as_of');
        $currentAssessments = Assessment::with(Assessment::AGING_RELATIONS)->currentOn($asOf)->get();
        $listedIds = $this->buildAgingListQuery($request)->pluck('assessments.id');

        return response()->json(['ar_aging' => (new ArAgingBuilder($asOf))->build($currentAssessments, $listedIds)]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    /** @return Builder<Assessment> */
    private function buildAgingListQuery(ShowArAgingRequest $request): Builder
    {
        $agingQuery = Assessment::query()
            ->select('assessments.id')
            ->join('students', 'students.id', '=', 'assessments.student_id')
            ->join('school_years', 'school_years.id', '=', 'assessments.school_year_id')
            ->currentOn($request->validated('as_of'))
            ->when($request->filled('school_year_id'), fn (Builder $query) => $query->where('assessments.school_year_id', $request->validated('school_year_id')))
            ->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('assessments.grade_level_id', $request->validated('grade_level_id')))
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('students.id')
            ->orderBy('school_years.start_date')
            ->orderBy('assessments.id');

        $this->applySearch($agingQuery, (string) $request->validated('search'));

        return $agingQuery;
    }
}
