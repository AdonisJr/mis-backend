<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListAssessmentsRequest;
use App\Http\Requests\PreviewAssessmentRequest;
use App\Http\Requests\ReassessAssessmentRequest;
use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Http\Resources\AssessmentRowResource;
use App\Models\Assessment;
use App\Models\Student;
use App\Support\AssessmentCalculator;
use App\Support\BillingPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AssessmentController extends Controller
{
    use SearchesPaginatedLists;

    public const SUPERSEDED_MESSAGE = 'Only the current assessment can be re-assessed.';

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    public function listAssessments(ListAssessmentsRequest $request): JsonResponse
    {
        $assessmentPage = $this->buildListQuery($request)->paginate();

        return response()->json([
            'assessments' => AssessmentRowResource::collection($assessmentPage->getCollection()),
            'meta' => $this->buildPageMeta($assessmentPage),
        ]);
    }

    public function showAssessment(int $id): JsonResponse
    {
        return response()->json([
            'assessment' => AssessmentResource::make(Assessment::with(Assessment::DETAIL_RELATIONS)->findOrFail($id)),
        ]);
    }

    public function listHistory(int $id): JsonResponse
    {
        $storedAssessment = Assessment::findOrFail($id);
        $historyRows = Assessment::with(Assessment::LIST_RELATIONS)->sameStudentYear($storedAssessment)->orderByDesc('id')->get();

        return response()->json([
            'assessments' => AssessmentRowResource::collection($historyRows),
        ]);
    }

    public function previewAssessment(PreviewAssessmentRequest $request): JsonResponse
    {
        $draft = (new AssessmentCalculator($request->buildAssessmentCandidate()))->buildEligibleDraft();

        return response()->json([
            'assessment' => AssessmentResource::make($draft),
        ]);
    }

    public function createAssessment(StoreAssessmentRequest $request): JsonResponse
    {
        $assessment = DB::transaction(fn () => $this->createWithinLock($request), Assessment::TRANSACTION_ATTEMPTS);

        return response()->json([
            'assessment' => AssessmentResource::make($assessment),
        ], Response::HTTP_CREATED);
    }

    public function reassessAssessment(ReassessAssessmentRequest $request, int $id): JsonResponse
    {
        $storedAssessment = Assessment::findOrFail($id);
        $assessment = DB::transaction(fn () => $this->reassessWithinLock($storedAssessment, $request), Assessment::TRANSACTION_ATTEMPTS);

        if ($assessment === null) {
            return response()->json(['message' => self::SUPERSEDED_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'assessment' => AssessmentResource::make($assessment),
        ], Response::HTTP_CREATED);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildListQuery(ListAssessmentsRequest $request): Builder
    {
        $assessmentQuery = Assessment::query()
            // select only assessment columns: the joined students table also has id and timestamps
            ->select('assessments.*')
            ->join('students', 'students.id', '=', 'assessments.student_id')
            ->with(Assessment::LIST_RELATIONS)
            ->current()
            ->where('assessments.school_year_id', $request->validated('school_year_id'))
            ->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('assessments.grade_level_id', $request->validated('grade_level_id')))
            ->when($request->filled('payment_plan_id'), fn (Builder $query) => $query->where('assessments.payment_plan_id', $request->validated('payment_plan_id')))
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('assessments.id');
        $this->applySearch($assessmentQuery, (string) $request->validated('search'));

        return $assessmentQuery;
    }

    private function createWithinLock(StoreAssessmentRequest $request): Assessment
    {
        $candidate = $request->buildAssessmentCandidate();
        // every assessment of a student serializes on this row, so two requests cannot both pass the held check
        Student::whereKey($candidate->student_id)->lockForUpdate()->first();
        $candidate->lockPaymentPlan();

        if (Assessment::current()->sameStudentYear($candidate)->exists()) {
            throw ValidationException::withMessages(['student_id' => StoreAssessmentRequest::ALREADY_ASSESSED_MESSAGE]);
        }

        $assessment = (new AssessmentCalculator($candidate))->buildEligibleDraft()->saveReplacing(null);
        (new BillingPosting($request->user()))->postAssessment($assessment);

        return $assessment;
    }

    private function reassessWithinLock(Assessment $storedAssessment, ReassessAssessmentRequest $request): ?Assessment
    {
        Student::whereKey($storedAssessment->student_id)->lockForUpdate()->first();
        // re-read under the lock: a second tab may have superseded this row meanwhile
        $currentAssessment = Assessment::lockForUpdate()->findOrFail($storedAssessment->id);

        if ($currentAssessment->status === Assessment::STATUS_SUPERSEDED) {
            return null;
        }

        if ($currentAssessment->hasValidPayments()) {
            throw ValidationException::withMessages(['reassess' => Assessment::HAS_PAYMENTS_MESSAGE]);
        }

        $candidate = $currentAssessment->buildReassessCandidate((int) $request->validated('payment_plan_id'));
        $candidate->forceFill(['created_by' => $request->user()->id]);
        $candidate->lockPaymentPlan();

        $replacement = (new AssessmentCalculator($candidate))->buildEligibleDraft()->saveReplacing($currentAssessment);
        (new BillingPosting($request->user()))->repostAssessment($currentAssessment, $replacement);

        return $replacement;
    }
}
