<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Support\StudentAccountCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SectionAccountController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function generateAccounts(int $id): JsonResponse
    {
        Section::findOrFail($id);

        [$createdRows, $skippedCount] = DB::transaction(function () use ($id) {
            [$unlinkedStudents, $linkedStudents] = $this->lockCurrentStudents($id)->partition(fn (Student $student) => $student->user_id === null);

            return [$this->createAccounts($unlinkedStudents), $linkedStudents->count()];
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'accounts' => $createdRows->values()->all(),
            'created_count' => $createdRows->count(),
            'skipped_count' => $skippedCount,
        ]);
    }

    /** @return Collection<int, Student> */
    private function lockCurrentStudents(int $sectionId): Collection
    {
        return Student::whereIn('id', Enrollment::current()->where('section_id', $sectionId)->select('student_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return Collection<int, array{student_id: int, lrn: string, name: string, temporary_password: string}>
     */
    private function createAccounts(Collection $students): Collection
    {
        return $students
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc'], ['id', 'asc']])
            ->map(fn (Student $student) => [
                'student_id' => $student->id,
                'lrn' => $student->lrn,
                'name' => $student->buildFullName(),
                'temporary_password' => (new StudentAccountCreator($student))->create(),
            ]);
    }
}
