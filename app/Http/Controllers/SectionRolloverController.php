<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteSectionRequest;
use App\Http\Requests\PreviewSectionRolloverRequest;
use App\Http\Requests\RolloverSectionRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectionRolloverController extends Controller
{
    public const LAST_TERM_MESSAGE = 'The term is the last term of its school year.';

    public const NEXT_TERM_MISSING_MESSAGE = 'The next term of this school year has not been created yet.';

    public const INVALID_SELECTION_MESSAGE = 'Only enrolled records of this section and term can be rolled over.';

    public const NOT_ENOUGH_SEATS_MESSAGE = 'The section does not have enough seats left in the next term.';

    private const TRANSACTION_ATTEMPTS = 3;

    // set by resolveEndedAt inside one transaction closure, then read by the two writers
    private ?string $resolvedEndedAt = null;

    public function previewRollover(PreviewSectionRolloverRequest $request, int $id): JsonResponse
    {
        $section = Section::findOrFail($id);
        $sourceTerm = Term::with('schoolYear')->findOrFail($request->validated('term_id'));
        $targetTerm = $sourceTerm->findNextTerm();
        $sourceEnrollments = $this->listSourceEnrollments($id, $sourceTerm->id);
        $targetCandidate = $targetTerm ? $this->buildTargetCandidate($section, $targetTerm) : null;
        $enrolledStudentIds = $targetCandidate ? $this->findStudentsEnrolledIn($targetCandidate, $sourceEnrollments->pluck('student_id')) : collect();

        return response()->json([
            'target_term' => $this->buildTermSummary($targetTerm),
            'seats_left' => $targetCandidate ? $this->countSeatsLeft($targetCandidate) : null,
            'enrollments' => $sourceEnrollments
                ->map(fn (Enrollment $enrollment) => $this->buildCandidateRow($enrollment, $enrolledStudentIds->contains($enrollment->student_id)))
                ->values(),
        ]);
    }

    public function rolloverSection(RolloverSectionRequest $request, int $id): JsonResponse
    {
        Section::findOrFail($id);

        $rolloverOutcome = DB::transaction(fn () => $this->rolloverWithinLock($request), self::TRANSACTION_ATTEMPTS);

        return response()->json($rolloverOutcome);
    }

    public function completeSection(CompleteSectionRequest $request, int $id): JsonResponse
    {
        Section::findOrFail($id);

        $completedCount = DB::transaction(fn () => $this->completeWithinLock($request), self::TRANSACTION_ATTEMPTS);

        return response()->json(['completed_count' => $completedCount]);
    }

    private function rolloverWithinLock(RolloverSectionRequest $request): array
    {
        $targetTerm = $this->resolveTargetTerm(Term::with('schoolYear')->findOrFail($request->validated('term_id')));
        $lockedEnrollments = $this->lockSelectedEnrollments($request);
        $section = Section::lockForUpdate()->findOrFail((int) $request->route('id'));
        $this->rejectForeignSelection($lockedEnrollments, $request);

        $targetCandidate = $this->buildTargetCandidate($section, $targetTerm);
        $enrolledStudentIds = $this->findStudentsEnrolledIn($targetCandidate, $lockedEnrollments->pluck('student_id'));
        $isAlreadyEnrolled = fn (Enrollment $enrollment) => $enrolledStudentIds->contains($enrollment->student_id);
        $enrollmentsToCopy = $lockedEnrollments->reject($isAlreadyEnrolled)->values();
        $skippedEnrollments = $lockedEnrollments->filter($isAlreadyEnrolled)->values();

        $this->resolveEndedAt($request, $enrollmentsToCopy);
        $this->rejectMissingSeats($targetCandidate, $enrollmentsToCopy->count());
        $this->markCompleted($enrollmentsToCopy, $request);
        $this->openTargetEnrollments($enrollmentsToCopy, $targetTerm);

        return [
            'copied_count' => $enrollmentsToCopy->count(),
            'skipped' => $skippedEnrollments->load(EnrollmentController::RELATIONS)
                ->map(fn (Enrollment $enrollment) => $this->buildCandidateRow($enrollment, true))
                ->values(),
        ];
    }

    private function completeWithinLock(CompleteSectionRequest $request): int
    {
        $openIds = Enrollment::enrolled()
            ->where('section_id', (int) $request->route('id'))
            ->where('term_id', $request->validated('term_id'))
            ->pluck('id');
        $lockedEnrollments = $this->lockEnrollments($openIds)
            ->filter(fn (Enrollment $enrollment) => $enrollment->status === Enrollment::STATUS_ENROLLED)
            ->values();

        $this->resolveEndedAt($request, $lockedEnrollments);
        $this->markCompleted($lockedEnrollments, $request);

        return $lockedEnrollments->count();
    }

    private function resolveTargetTerm(Term $sourceTerm): Term
    {
        if ($sourceTerm->isLastTerm()) {
            throw ValidationException::withMessages(['term_id' => self::LAST_TERM_MESSAGE]);
        }

        $targetTerm = $sourceTerm->findNextTerm();

        if (! $targetTerm) {
            throw ValidationException::withMessages(['term_id' => self::NEXT_TERM_MISSING_MESSAGE]);
        }

        return $targetTerm;
    }

    private function lockSelectedEnrollments(RolloverSectionRequest $request): Collection
    {
        $selectedIds = collect($request->validated('enrollment_ids'))->map(fn ($enrollmentId) => (int) $enrollmentId);

        return $this->lockEnrollments($selectedIds);
    }

    private function lockEnrollments(SupportCollection $enrollmentIds): Collection
    {
        $sortedIds = $enrollmentIds->sort()->values();

        // lock order is student, enrollment, section, each batch sorted by id: the same order as MIS-13, so no deadlock
        Student::whereKey(Enrollment::whereKey($sortedIds)->pluck('student_id')->unique())->orderBy('id')->lockForUpdate()->get();

        return Enrollment::whereKey($sortedIds)->orderBy('id')->lockForUpdate()->get();
    }

    private function rejectForeignSelection(Collection $lockedEnrollments, RolloverSectionRequest $request): void
    {
        $isMissingRow = $lockedEnrollments->count() !== count($request->validated('enrollment_ids'));
        $hasForeignRow = $lockedEnrollments->contains(fn (Enrollment $enrollment) => ! $this->isSelectable($enrollment, $request));

        if (! $isMissingRow && ! $hasForeignRow) {
            return;
        }

        throw ValidationException::withMessages(['enrollment_ids' => self::INVALID_SELECTION_MESSAGE]);
    }

    private function isSelectable(Enrollment $enrollment, RolloverSectionRequest $request): bool
    {
        return $enrollment->status === Enrollment::STATUS_ENROLLED
            && $enrollment->section_id === (int) $request->route('id')
            && $enrollment->term_id === (int) $request->validated('term_id');
    }

    private function resolveEndedAt(FormRequest $request, Collection $enrollmentsToClose): void
    {
        $endedAt = $request->validated('ended_at') ?? today()->toDateString();

        // Y-m-d strings compare in date order; an equal date is allowed
        if ($enrollmentsToClose->contains(fn (Enrollment $enrollment) => $endedAt < $enrollment->enrolled_at->toDateString())) {
            throw ValidationException::withMessages(['ended_at' => EnrollmentController::ENDED_BEFORE_ENROLLED_MESSAGE]);
        }

        $this->resolvedEndedAt = $endedAt;
    }

    private function rejectMissingSeats(Enrollment $targetCandidate, int $copyCount): void
    {
        if ($copyCount <= $this->countSeatsLeft($targetCandidate)) {
            return;
        }

        throw ValidationException::withMessages(['section_id' => self::NOT_ENOUGH_SEATS_MESSAGE]);
    }

    private function markCompleted(Collection $openEnrollments, FormRequest $request): void
    {
        if ($openEnrollments->isEmpty()) {
            return;
        }

        $completedFields = ['status' => Enrollment::STATUS_COMPLETED, 'ended_at' => $this->resolvedEndedAt];

        // absent remarks keep each row's own value
        if ($request->validated('remarks') !== null) {
            $completedFields['remarks'] = $request->validated('remarks');
        }

        Enrollment::whereKey($openEnrollments->modelKeys())->update($completedFields);
    }

    private function openTargetEnrollments(Collection $enrollmentsToCopy, Term $targetTerm): void
    {
        foreach ($enrollmentsToCopy as $sourceEnrollment) {
            Enrollment::create([
                'student_id' => $sourceEnrollment->student_id,
                'section_id' => $sourceEnrollment->section_id,
                'term_id' => $targetTerm->id,
                'status' => Enrollment::STATUS_ENROLLED,
                'enrolled_at' => $this->resolvedEndedAt,
            ]);
        }
    }

    private function buildTargetCandidate(Section $section, Term $targetTerm): Enrollment
    {
        $targetCandidate = new Enrollment(['section_id' => $section->id, 'term_id' => $targetTerm->id]);
        $targetCandidate->setRelation('section', $section);

        return $targetCandidate;
    }

    private function findStudentsEnrolledIn(Enrollment $targetCandidate, SupportCollection $studentIds): SupportCollection
    {
        return Enrollment::enrolled()->whereIn('student_id', $studentIds)->inPeriodOf($targetCandidate)->pluck('student_id');
    }

    private function countSeatsLeft(Enrollment $targetCandidate): int
    {
        return max($targetCandidate->section->capacity - $targetCandidate->countSeatsTaken(), 0);
    }

    private function listSourceEnrollments(int $sectionId, int $termId): Collection
    {
        // not paginated: the modal needs every student, and the list is bounded by the section's capacity
        return Enrollment::query()
            ->select('enrollments.*')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->enrolled()
            ->where('enrollments.section_id', $sectionId)
            ->where('enrollments.term_id', $termId)
            ->with(EnrollmentController::RELATIONS)
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('enrollments.id')
            ->get();
    }

    private function buildCandidateRow(Enrollment $enrollment, bool $isEnrolledInTarget): array
    {
        return [
            ...EnrollmentResource::make($enrollment)->resolve(),
            'is_enrolled_in_target' => $isEnrolledInTarget,
            'skip_reason' => $isEnrolledInTarget ? EnrollmentController::ALREADY_ENROLLED_MESSAGE : null,
        ];
    }

    private function buildTermSummary(?Term $term): ?array
    {
        if (! $term) {
            return null;
        }

        return ['id' => $term->id, 'term_number' => $term->term_number, 'label' => $term->getLabel()];
    }
}
