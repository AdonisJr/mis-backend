<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\CloseEnrollmentRequest;
use App\Http\Requests\ListEnrollmentsRequest;
use App\Http\Requests\MoveEnrollmentRequest;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentController extends Controller
{
    use SearchesPaginatedLists;

    public const ALREADY_ENROLLED_MESSAGE = 'The student is already enrolled for this period.';

    public const SECTION_FULL_MESSAGE = 'The section is full for this period.';

    public const SAME_SECTION_MESSAGE = 'The student is already in this section.';

    public const MOVE_TARGET_MESSAGE = 'The new section must be in the same school year and grade level.';

    public const ENDED_BEFORE_ENROLLED_MESSAGE = 'The end date cannot be before the enrolled date.';

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    private const RELATIONS = ['student', 'section.gradeLevel', 'section.program', 'term.schoolYear'];

    private const TRANSACTION_ATTEMPTS = 3;

    public function listEnrollments(ListEnrollmentsRequest $request): JsonResponse
    {
        $enrollmentQuery = $this->buildFilteredEnrollmentQuery($request);
        $this->applySearch($enrollmentQuery, (string) $request->validated('search'));
        $enrollmentPage = $enrollmentQuery->paginate();

        return response()->json([
            'enrollments' => EnrollmentResource::collection($enrollmentPage->getCollection()),
            'meta' => $this->buildPageMeta($enrollmentPage),
        ]);
    }

    public function enrollStudent(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = DB::transaction(fn () => $this->createWithinLock($request), self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'enrollment' => EnrollmentResource::make($enrollment->load(self::RELATIONS)),
        ], Response::HTTP_CREATED);
    }

    public function moveEnrollment(MoveEnrollmentRequest $request, int $id): JsonResponse
    {
        Enrollment::findOrFail($id);
        $newEnrollment = DB::transaction(fn () => $this->moveWithinLock($request), self::TRANSACTION_ATTEMPTS);

        return $this->buildChangedEnrollmentResponse($newEnrollment);
    }

    public function dropEnrollment(CloseEnrollmentRequest $request, int $id): JsonResponse
    {
        Enrollment::findOrFail($id);

        return $this->closeEnrollment($request, Enrollment::STATUS_DROPPED);
    }

    public function transferEnrollment(CloseEnrollmentRequest $request, int $id): JsonResponse
    {
        Enrollment::findOrFail($id);

        return $this->closeEnrollment($request, Enrollment::STATUS_TRANSFERRED);
    }

    public function completeEnrollment(CloseEnrollmentRequest $request, int $id): JsonResponse
    {
        Enrollment::findOrFail($id);

        return $this->closeEnrollment($request, Enrollment::STATUS_COMPLETED);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function closeEnrollment(CloseEnrollmentRequest $request, string $closingStatus): JsonResponse
    {
        $closedEnrollment = DB::transaction(fn () => $this->closeWithinLock($request, $closingStatus), self::TRANSACTION_ATTEMPTS);

        return $this->buildChangedEnrollmentResponse($closedEnrollment);
    }

    private function buildChangedEnrollmentResponse(?Enrollment $changedEnrollment): JsonResponse
    {
        if (! $changedEnrollment) {
            return response()->json(['message' => Enrollment::CLOSED_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'enrollment' => EnrollmentResource::make($changedEnrollment->load(self::RELATIONS)),
        ]);
    }

    private function createWithinLock(StoreEnrollmentRequest $request): Enrollment
    {
        // lock order is student, enrollment, section: a fixed order cannot deadlock, and the checks below run only once the locks are held
        Student::lockForUpdate()->findOrFail($request->validated('student_id'));
        $lockedSection = Section::lockForUpdate()->findOrFail($request->validated('section_id'));
        $candidate = new Enrollment([
            ...$request->validated(),
            'status' => Enrollment::STATUS_ENROLLED,
            'enrolled_at' => $request->validated('enrolled_at') ?? today()->toDateString(),
        ]);
        $candidate->setRelation('section', $lockedSection);

        $this->rejectOverlappingEnrollment($candidate);
        $this->rejectFullSection($candidate);
        $candidate->save();

        return $candidate;
    }

    private function moveWithinLock(MoveEnrollmentRequest $request): ?Enrollment
    {
        $sourceEnrollment = $this->lockOpenEnrollment((int) $request->route('id'));

        if (! $sourceEnrollment) {
            return null;
        }

        $endedAt = $this->resolveEndedAt($request, $sourceEnrollment);
        $targetSection = Section::lockForUpdate()->findOrFail($request->validated('section_id'));
        $this->rejectUnfitMoveTarget($sourceEnrollment, $targetSection);

        $candidate = new Enrollment([
            'student_id' => $sourceEnrollment->student_id,
            'section_id' => $targetSection->id,
            'term_id' => $sourceEnrollment->term_id,
            'status' => Enrollment::STATUS_ENROLLED,
            'enrolled_at' => $endedAt,
        ]);
        $candidate->setRelation('section', $targetSection);
        $this->rejectFullSection($candidate);

        $sourceEnrollment->update([
            'status' => Enrollment::STATUS_MOVED,
            'ended_at' => $endedAt,
            'remarks' => $request->validated('remarks') ?? $sourceEnrollment->remarks,
        ]);
        $candidate->save();

        return $candidate;
    }

    private function closeWithinLock(CloseEnrollmentRequest $request, string $closingStatus): ?Enrollment
    {
        $openEnrollment = $this->lockOpenEnrollment((int) $request->route('id'));

        if (! $openEnrollment) {
            return null;
        }

        $openEnrollment->update([
            'status' => $closingStatus,
            'ended_at' => $this->resolveEndedAt($request, $openEnrollment),
            'remarks' => $request->validated('remarks') ?? $openEnrollment->remarks,
        ]);

        return $openEnrollment;
    }

    private function lockOpenEnrollment(int $id): ?Enrollment
    {
        // the student row is locked first, in the same order as enrolling, so the two paths cannot deadlock
        Student::lockForUpdate()->find(Enrollment::whereKey($id)->value('student_id'));
        $lockedEnrollment = Enrollment::lockForUpdate()->findOrFail($id);

        return $lockedEnrollment->status === Enrollment::STATUS_ENROLLED ? $lockedEnrollment : null;
    }

    private function resolveEndedAt(CloseEnrollmentRequest $request, Enrollment $enrollment): string
    {
        $endedAt = $request->validated('ended_at') ?? today()->toDateString();

        // Y-m-d strings compare in date order; an equal date is allowed
        if ($endedAt < $enrollment->enrolled_at->toDateString()) {
            throw ValidationException::withMessages(['ended_at' => self::ENDED_BEFORE_ENROLLED_MESSAGE]);
        }

        return $endedAt;
    }

    private function rejectOverlappingEnrollment(Enrollment $candidate): void
    {
        if (! $candidate->hasOverlappingEnrollment()) {
            return;
        }

        throw ValidationException::withMessages(['student_id' => self::ALREADY_ENROLLED_MESSAGE]);
    }

    private function rejectFullSection(Enrollment $candidate): void
    {
        if ($candidate->countSeatsTaken() < $candidate->section->capacity) {
            return;
        }

        throw ValidationException::withMessages(['section_id' => self::SECTION_FULL_MESSAGE]);
    }

    private function rejectUnfitMoveTarget(Enrollment $sourceEnrollment, Section $targetSection): void
    {
        $sourceSection = $sourceEnrollment->section;

        if ($targetSection->is($sourceSection)) {
            throw ValidationException::withMessages(['section_id' => self::SAME_SECTION_MESSAGE]);
        }

        $isSameYearAndGrade = $targetSection->school_year_id === $sourceSection->school_year_id && $targetSection->grade_level_id === $sourceSection->grade_level_id;

        if (! $isSameYearAndGrade) {
            throw ValidationException::withMessages(['section_id' => self::MOVE_TARGET_MESSAGE]);
        }
    }

    private function buildFilteredEnrollmentQuery(ListEnrollmentsRequest $request): Builder
    {
        // select only enrollment columns: the joined tables also have id and timestamps
        return Enrollment::query()
            ->select('enrollments.*')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('sections', 'sections.id', '=', 'enrollments.section_id')
            ->with(self::RELATIONS)
            ->where('sections.school_year_id', $request->validated('school_year_id'))
            // JHS rows have no term, so a term filter keeps them as whole-year rows
            ->when($request->filled('term_id'), fn (Builder $query) => $query->where(fn (Builder $termQuery) => $termQuery->whereNull('enrollments.term_id')->orWhere('enrollments.term_id', $request->validated('term_id'))))
            ->when($request->filled('section_id'), fn (Builder $query) => $query->where('enrollments.section_id', $request->validated('section_id')))
            ->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('sections.grade_level_id', $request->validated('grade_level_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('enrollments.status', $request->validated('status')))
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('enrollments.id');
    }
}
