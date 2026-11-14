<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListStudentDiscountsRequest;
use App\Http\Requests\StoreStudentDiscountRequest;
use App\Http\Requests\UpdateStudentDiscountRequest;
use App\Http\Resources\StudentDiscountResource;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentDiscountController extends Controller
{
    use SearchesPaginatedLists;

    public const RELATIONS = ['student', 'discountType', 'studentEnrollments.section.gradeLevel'];

    private const SEARCH_COLUMNS = ['students.lrn', 'students.first_name', 'students.middle_name', 'students.last_name'];

    public function listStudentDiscounts(ListStudentDiscountsRequest $request): JsonResponse
    {
        $studentDiscountPage = $this->buildFilteredQuery($request)->paginate();

        return response()->json([
            'student_discounts' => StudentDiscountResource::collection($studentDiscountPage->getCollection()),
            'meta' => $this->buildPageMeta($studentDiscountPage),
        ]);
    }

    public function createStudentDiscount(StoreStudentDiscountRequest $request): JsonResponse
    {
        $studentDiscount = DB::transaction(fn () => $this->createWithinLock($request), StudentDiscount::TRANSACTION_ATTEMPTS);

        return response()->json([
            'student_discount' => StudentDiscountResource::make($studentDiscount),
        ], Response::HTTP_CREATED);
    }

    public function updateStudentDiscount(UpdateStudentDiscountRequest $request, int $id): JsonResponse
    {
        $studentDiscount = StudentDiscount::findOrFail($id);
        $studentDiscount->update(['reference_no' => $request->validated('reference_no'), 'remarks' => $request->validated('remarks')]);

        return response()->json([
            'student_discount' => StudentDiscountResource::make($studentDiscount->load(self::RELATIONS)),
        ]);
    }

    public function deleteStudentDiscount(int $id): Response
    {
        StudentDiscount::findOrFail($id)->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildFilteredQuery(ListStudentDiscountsRequest $request): Builder
    {
        $studentDiscountQuery = StudentDiscount::query()
            // select only assignment columns: the joined students table also has id and timestamps
            ->select('student_discounts.*')
            ->join('students', 'students.id', '=', 'student_discounts.student_id')
            ->with(self::RELATIONS)
            ->where('student_discounts.school_year_id', $request->validated('school_year_id'))
            ->when($request->filled('discount_type_id'), fn (Builder $query) => $query->where('student_discounts.discount_type_id', $request->validated('discount_type_id')))
            ->when($request->filled('student_id'), fn (Builder $query) => $query->where('student_discounts.student_id', $request->validated('student_id')))
            ->when($request->filled('source'), fn (Builder $query) => $query->whereHas('discountType', fn (Builder $typeQuery) => $typeQuery->where('source', $request->validated('source'))))
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->orderBy('student_discounts.id');
        $this->applySearch($studentDiscountQuery, (string) $request->validated('search'));

        return $studentDiscountQuery;
    }

    private function createWithinLock(StoreStudentDiscountRequest $request): StudentDiscount
    {
        $candidate = $request->buildAssignmentCandidate();
        // every assignment of a student serializes on this row, so two requests cannot both pass the held checks
        Student::whereKey($candidate->student_id)->lockForUpdate()->first();
        $this->rejectConflictingAssignment($candidate);

        $candidate->fill($request->safe()->only(['reference_no', 'remarks']))->save();

        return $candidate->load(self::RELATIONS);
    }

    private function rejectConflictingAssignment(StudentDiscount $candidate): void
    {
        if ($candidate->isTypeAlreadyHeld()) {
            throw ValidationException::withMessages(['discount_type_id' => StoreStudentDiscountRequest::TYPE_ALREADY_HELD_MESSAGE]);
        }

        if ($candidate->hasOtherGovernmentType()) {
            throw ValidationException::withMessages(['discount_type_id' => StoreStudentDiscountRequest::GOVERNMENT_LIMIT_MESSAGE]);
        }
    }
}
