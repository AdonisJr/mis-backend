<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListStudentsRequest;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StudentController extends Controller
{
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

    private function applySearch(Builder $studentQuery, string $search): void
    {
        // no `u` flag: mb_strtolower already replaced invalid UTF-8, and preg_split with `u` would return false on it
        $searchWords = preg_split('/\s+/', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($searchWords as $searchWord) {
            $this->applySearchWord($studentQuery, $searchWord);
        }
    }

    private function applySearchWord(Builder $studentQuery, string $searchWord): void
    {
        $studentQuery->where(function (Builder $wordQuery) use ($searchWord) {
            foreach (self::SEARCH_COLUMNS as $column) {
                // LOWER on both sides keeps the match case-insensitive on SQLite, MySQL and PostgreSQL alike
                $wordQuery->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.$searchWord.'%']);
            }
        });
    }

    private function buildPageMeta(LengthAwarePaginator $studentPage): array
    {
        return [
            'current_page' => $studentPage->currentPage(),
            'last_page' => $studentPage->lastPage(),
            'per_page' => $studentPage->perPage(),
            'total' => $studentPage->total(),
        ];
    }
}
