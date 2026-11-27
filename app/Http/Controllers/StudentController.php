<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListStudentsRequest;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Requests\UpdateStudentRfidRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentController extends Controller
{
    use SearchesPaginatedLists;

    public const ENROLLMENTS_DELETE_MESSAGE = 'The student has enrollments and cannot be deleted.';

    public const DISCOUNTS_DELETE_MESSAGE = 'The student has discounts and cannot be deleted.';

    public const ASSESSMENTS_DELETE_MESSAGE = 'The student has assessments and cannot be deleted.';

    private const SEARCH_COLUMNS = ['lrn', 'first_name', 'middle_name', 'last_name'];

    private const CURRENT_ENROLLMENT_RELATIONS = ['currentEnrollments.section.gradeLevel'];

    public function listStudents(ListStudentsRequest $request): JsonResponse
    {
        $studentQuery = Student::query()->with(self::CURRENT_ENROLLMENT_RELATIONS)->orderByDesc('created_at')->orderByDesc('id');
        $this->applySearch($studentQuery, (string) $request->validated('search'));
        $studentQuery->when($request->filled('grade_level_id'), fn (Builder $query) => $query->whereHas('currentEnrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery->whereHas('section', fn (Builder $sectionQuery) => $sectionQuery->where('grade_level_id', $request->validated('grade_level_id')))));
        $studentQuery->when($request->filled('has_rfid'), fn (Builder $query) => $request->boolean('has_rfid') ? $query->whereNotNull('rfid') : $query->whereNull('rfid'));
        $studentPage = $studentQuery->paginate();

        return response()->json([
            'students' => StudentResource::collection($studentPage->getCollection()),
            'meta' => $this->buildPageMeta($studentPage),
        ]);
    }

    public function createStudent(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated())->load(self::CURRENT_ENROLLMENT_RELATIONS);

        return response()->json([
            'student' => StudentResource::make($student),
        ], Response::HTTP_CREATED);
    }

    public function updateStudent(UpdateStudentRequest $request, int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        $student->update($request->validated());
        $student->load(self::CURRENT_ENROLLMENT_RELATIONS);

        return response()->json([
            'student' => StudentResource::make($student),
        ]);
    }

    public function updateStudentRfid(UpdateStudentRfidRequest $request, int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        $this->saveStudentRfid($student, $request->validated('rfid'));

        return response()->json([
            'student' => StudentResource::make($student->load(self::CURRENT_ENROLLMENT_RELATIONS)),
        ]);
    }

    public function deleteStudentRfid(int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        $student->forceFill(['rfid' => null])->save();

        return response()->json([
            'student' => StudentResource::make($student->load(self::CURRENT_ENROLLMENT_RELATIONS)),
        ]);
    }

    public function deleteStudent(int $id): Response
    {
        $student = Student::findOrFail($id);

        if ($student->enrollments()->exists()) {
            return response()->json(['message' => self::ENROLLMENTS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($student->discounts()->exists()) {
            return response()->json(['message' => self::DISCOUNTS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($student->assessments()->exists()) {
            return response()->json(['message' => self::ASSESSMENTS_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $student->delete();

        return response()->noContent();
    }

    private function saveStudentRfid(Student $student, string $rfid): void
    {
        try {
            $student->forceFill(['rfid' => $rfid])->save();
        } catch (UniqueConstraintViolationException) {
            // another request took the card between the request check and this write; the unique index is the final guard
            throw ValidationException::withMessages(['rfid' => UpdateStudentRfidRequest::buildCardTakenMessage(Student::where('rfid', $rfid)->firstOrFail())]);
        }
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }
}
