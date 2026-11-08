<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListStudentsRequest;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StudentController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['lrn', 'first_name', 'middle_name', 'last_name'];

    public function listStudents(ListStudentsRequest $request): JsonResponse
    {
        $studentQuery = Student::query()->orderByDesc('created_at')->orderByDesc('id');
        $this->applySearch($studentQuery, (string) $request->validated('search'));
        $studentQuery->when($request->filled('grade_level'), fn (Builder $query) => $query->where('grade_level', $request->validated('grade_level')));
        $studentQuery->when($request->filled('section'), fn (Builder $query) => $query->where('section', $request->validated('section')));
        $studentPage = $studentQuery->paginate();

        return response()->json([
            'students' => StudentResource::collection($studentPage->getCollection()),
            'meta' => $this->buildPageMeta($studentPage),
        ]);
    }

    public function createStudent(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        return response()->json([
            'student' => StudentResource::make($student),
        ], Response::HTTP_CREATED);
    }

    public function updateStudent(UpdateStudentRequest $request, int $id): JsonResponse
    {
        $student = Student::findOrFail($id);
        $student->update($request->validated());

        return response()->json([
            'student' => StudentResource::make($student),
        ]);
    }

    public function deleteStudent(int $id): Response
    {
        Student::findOrFail($id)->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }
}
