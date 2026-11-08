<?php

namespace App\Http\Controllers;

use App\Http\Resources\GradeLevelResource;
use App\Models\GradeLevel;
use Illuminate\Http\JsonResponse;

class GradeLevelController extends Controller
{
    public function listGradeLevels(): JsonResponse
    {
        $gradeLevels = GradeLevel::orderBy('sort_order')->get();

        return response()->json([
            'grade_levels' => GradeLevelResource::collection($gradeLevels),
        ]);
    }
}
