<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowClassQuarterRequest;
use App\Http\Resources\ClassRecordResource;
use Illuminate\Http\JsonResponse;

class ClassRecordController extends Controller
{
    public function showClassRecord(ShowClassQuarterRequest $request): JsonResponse
    {
        return response()->json(ClassRecordResource::makeEnvelope($request->findSchoolClass(), $request->resolveQuarter()));
    }
}
