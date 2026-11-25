<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowReportCardRequest;
use App\Http\Requests\ShowSectionReportCardsRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Support\ReportCardBuilder;
use Illuminate\Http\JsonResponse;

class ReportCardController extends Controller
{
    public function showReportCard(ShowReportCardRequest $request): JsonResponse
    {
        return response()->json([
            'report_card' => $this->buildReportCard($request->findStudent(), $request->findSchoolYear()),
        ]);
    }

    public function listSectionReportCards(ShowSectionReportCardsRequest $request): JsonResponse
    {
        $section = $request->findSection();
        $schoolYear = $section->schoolYear;

        return response()->json([
            'section' => ['id' => $section->id, 'name' => $section->name],
            'school_year' => ['id' => $schoolYear->id, 'name' => $schoolYear->name, 'term_type' => $schoolYear->term_type],
            'report_cards' => $section->fetchListedStudents()
                ->map(fn (Student $student) => $this->buildReportCard($student, $schoolYear))
                ->values(),
        ]);
    }

    private function buildReportCard(Student $student, SchoolYear $schoolYear): ReportCardResource
    {
        return ReportCardResource::make((new ReportCardBuilder($student, $schoolYear))->build());
    }
}
