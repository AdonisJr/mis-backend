<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowMobileAttendanceRequest;
use App\Http\Resources\StudentAttendanceResource;
use App\Support\MonthAttendanceSummary;
use Illuminate\Http\JsonResponse;

class MobileAttendanceController extends Controller
{
    public function showAttendance(ShowMobileAttendanceRequest $request): JsonResponse
    {
        $summary = new MonthAttendanceSummary($request->findStudent(), $request->validated('month'));
        $days = $summary->listDays();

        return response()->json([
            'month' => $request->validated('month'),
            'summary' => $summary->countSummary($days),
            'days' => StudentAttendanceResource::collection($days),
        ]);
    }
}
