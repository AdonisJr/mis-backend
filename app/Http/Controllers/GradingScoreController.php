<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveGradingScoresRequest;
use App\Http\Resources\ClassRecordResource;
use App\Models\GradingScore;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class GradingScoreController extends Controller
{
    public function saveScores(SaveGradingScoresRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            $request->findSchoolClass()->lockOpenQuarter($request->resolveQuarter());
            // one statement, so a failure part-way leaves nothing written
            GradingScore::upsert($request->buildUpsertRows(), ['grading_activity_id', 'student_id'], ['score', 'updated_at']);
        }, SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json(ClassRecordResource::makeEnvelope($request->findSchoolClass(), $request->resolveQuarter()));
    }
}
