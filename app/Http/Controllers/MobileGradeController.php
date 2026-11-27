<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowMobileGradesRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\SchoolYear;
use App\Support\ReportCardBuilder;
use App\Support\StudentGradeYears;
use Illuminate\Http\JsonResponse;

class MobileGradeController extends Controller
{
    public function showGrades(ShowMobileGradesRequest $request): JsonResponse
    {
        $student = $request->findStudent();
        $gradeYears = new StudentGradeYears($student);
        $years = $gradeYears->listYears();
        $schoolYear = $request->filled('school_year_id')
            ? SchoolYear::findOrFail($request->validated('school_year_id'))
            : $gradeYears->resolveDefaultYear($years);

        return response()->json([
            'school_year' => $schoolYear ? $this->buildYearSummary($schoolYear) : null,
            'school_years' => $years->map(fn (SchoolYear $year) => $this->buildYearSummary($year))->all(),
            'report_card' => $schoolYear ? ReportCardResource::make((new ReportCardBuilder($student, $schoolYear))->build()) : null,
        ]);
    }

    /** @return array{id: int, name: string} */
    private function buildYearSummary(SchoolYear $schoolYear): array
    {
        return ['id' => $schoolYear->id, 'name' => $schoolYear->name];
    }
}
