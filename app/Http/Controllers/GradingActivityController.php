<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeClassQuarterRequest;
use App\Http\Requests\ShowClassQuarterRequest;
use App\Http\Requests\StoreGradingActivityRequest;
use App\Http\Requests\UpdateGradingActivityRequest;
use App\Http\Resources\GradingActivityResource;
use App\Models\GradingActivity;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GradingActivityController extends Controller
{
    public function listActivities(ShowClassQuarterRequest $request): JsonResponse
    {
        $activities = $request->findSchoolClass()->gradingActivities()->where('quarter', $request->resolveQuarter())->get();

        return response()->json([
            'activities' => GradingActivityResource::collection(GradingActivity::sortForRecord($activities)),
        ]);
    }

    public function createActivity(StoreGradingActivityRequest $request): JsonResponse
    {
        $activity = DB::transaction(fn () => $this->saveNewActivity($request), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json(['activity' => GradingActivityResource::make($activity)], Response::HTTP_CREATED);
    }

    public function updateActivity(UpdateGradingActivityRequest $request): JsonResponse
    {
        $activity = DB::transaction(fn () => $this->saveChangedActivity($request), SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->json(['activity' => GradingActivityResource::make($activity)]);
    }

    public function deleteActivity(ChangeClassQuarterRequest $request): Response
    {
        DB::transaction(function () use ($request) {
            $request->findSchoolClass()->lockOpenQuarter($request->resolveQuarter());
            $request->findActivity()->delete();
        }, SchoolClass::TRANSACTION_ATTEMPTS);

        return response()->noContent();
    }

    private function saveNewActivity(StoreGradingActivityRequest $request): GradingActivity
    {
        $schoolClass = $request->findSchoolClass();
        $schoolClass->lockOpenQuarter($request->resolveQuarter());
        $this->rejectSecondQa($request);

        $activity = new GradingActivity($request->validated());
        $activity->forceFill(['class_id' => $schoolClass->id, 'quarter' => $request->resolveQuarter()])->save();

        return $activity;
    }

    private function saveChangedActivity(UpdateGradingActivityRequest $request): GradingActivity
    {
        $request->findSchoolClass()->lockOpenQuarter($request->resolveQuarter());
        $this->rejectSecondQa($request);
        $activity = $request->findActivity();
        $recordedScore = $activity->findScoreAbove($request->validated('highest_possible_score'));

        if ($recordedScore !== null) {
            throw ValidationException::withMessages(['highest_possible_score' => sprintf(UpdateGradingActivityRequest::BELOW_RECORDED_SCORE_MESSAGE, $recordedScore)]);
        }

        $activity->fill($request->validated())->save();

        return $activity;
    }

    // the request checked this before the lock; a competing create could have added the QA since
    private function rejectSecondQa(StoreGradingActivityRequest $request): void
    {
        $isQa = $request->validated('component') === GradingActivity::COMPONENT_QA;

        if ($isQa && $request->findSchoolClass()->hasQaActivity($request->resolveQuarter(), $request->findActivity()?->id)) {
            throw ValidationException::withMessages(['component' => StoreGradingActivityRequest::SECOND_QA_MESSAGE]);
        }
    }
}
