<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolYearRequest;
use App\Http\Requests\UpdateSchoolYearRequest;
use App\Http\Resources\SchoolYearResource;
use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SchoolYearController extends Controller
{
    public const ACTIVE_DELETE_MESSAGE = 'The active school year cannot be deleted.';

    private const TRANSACTION_ATTEMPTS = 3;

    public function listSchoolYears(): JsonResponse
    {
        $schoolYears = SchoolYear::withCount('terms')->orderByDesc('start_date')->orderByDesc('id')->get();

        return response()->json([
            'school_years' => SchoolYearResource::collection($schoolYears),
        ]);
    }

    public function createSchoolYear(StoreSchoolYearRequest $request): JsonResponse
    {
        $schoolYear = SchoolYear::create($request->validated())->loadCount('terms');

        return response()->json([
            'school_year' => SchoolYearResource::make($schoolYear),
        ], Response::HTTP_CREATED);
    }

    public function updateSchoolYear(UpdateSchoolYearRequest $request, int $id): JsonResponse
    {
        $schoolYear = SchoolYear::findOrFail($id);
        $schoolYear->update($request->validated());
        $schoolYear->loadCount('terms');

        return response()->json([
            'school_year' => SchoolYearResource::make($schoolYear),
        ]);
    }

    public function deleteSchoolYear(int $id): Response
    {
        SchoolYear::findOrFail($id);
        $deletedCount = SchoolYear::whereKey($id)->where('is_active', false)->delete();

        // the conditional delete is the guard, so a concurrent set-active cannot slip in between check and delete
        if ($deletedCount === 0) {
            return response()->json(['message' => self::ACTIVE_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    public function setActiveSchoolYear(int $id): JsonResponse
    {
        $activeSchoolYear = DB::transaction(function () use ($id) {
            $schoolYear = SchoolYear::findOrFail($id);
            // unconditional update locks every row, so concurrent set-active calls serialize and cannot leave two active rows
            SchoolYear::query()->update(['is_active' => false]);
            SchoolYear::whereKey($id)->update(['is_active' => true]);
            // clears only an active term outside the year being activated, so re-activating the same year keeps its term
            Term::where('is_active', true)->where('school_year_id', '!=', $id)->update(['is_active' => false]);

            return $schoolYear->refresh()->loadCount('terms');
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json([
            'school_year' => SchoolYearResource::make($activeSchoolYear),
        ]);
    }

    public function fetchActiveSchoolYear(): JsonResponse
    {
        $activeSchoolYear = SchoolYear::withCount('terms')->where('is_active', true)->first();

        if (! $activeSchoolYear) {
            return response()->json(['school_year' => null]);
        }

        return response()->json([
            'school_year' => SchoolYearResource::make($activeSchoolYear),
        ]);
    }
}
