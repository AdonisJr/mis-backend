<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListAssessmentCandidatesRequest;
use App\Http\Resources\AssessmentCandidateResource;
use App\Models\FeeSchedule;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AssessmentCandidateController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['lrn', 'first_name', 'middle_name', 'last_name'];

    public function listCandidates(ListAssessmentCandidatesRequest $request): JsonResponse
    {
        $schoolYearId = (int) $request->validated('school_year_id');
        $candidateQuery = Student::query()
            ->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery
                ->eligibleForAssessment($schoolYearId)
                ->whereHas('section.gradeLevel', fn (Builder $gradeLevelQuery) => $gradeLevelQuery->whereIn('stage', FeeSchedule::BILLABLE_STAGES)))
            ->whereDoesntHave('assessments', fn (Builder $assessmentQuery) => $assessmentQuery->current()->where('assessments.school_year_id', $schoolYearId))
            ->with(['enrollments' => fn ($enrollmentRelation) => $enrollmentRelation->eligibleForAssessment($schoolYearId)->with(['section.gradeLevel', 'section.program'])])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
        $this->applySearch($candidateQuery, (string) $request->validated('search'));
        $candidatePage = $candidateQuery->paginate();

        return response()->json([
            'students' => AssessmentCandidateResource::collection($candidatePage->getCollection()),
            'meta' => $this->buildPageMeta($candidatePage),
        ]);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }
}
