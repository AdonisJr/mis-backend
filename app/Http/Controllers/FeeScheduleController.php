<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListFeeSchedulesRequest;
use App\Http\Requests\LookupFeeScheduleRequest;
use App\Http\Requests\StoreFeeScheduleRequest;
use App\Http\Requests\UpdateFeeScheduleRequest;
use App\Http\Resources\FeeScheduleDetailResource;
use App\Http\Resources\FeeScheduleResource;
use App\Models\FeeSchedule;
use App\Models\FeeScheduleLine;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FeeScheduleController extends Controller
{
    use SearchesPaginatedLists;

    public const RELATIONS = ['gradeLevel', 'program', 'lines.feeItem'];

    public function listFeeSchedules(ListFeeSchedulesRequest $request): JsonResponse
    {
        $feeScheduleQuery = FeeSchedule::query()->orderedForList()->with(self::RELATIONS);
        $feeScheduleQuery->where('fee_schedules.school_year_id', $request->validated('school_year_id'));
        $feeScheduleQuery->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('fee_schedules.grade_level_id', $request->validated('grade_level_id')));
        $feeScheduleQuery->when($request->filled('program_id'), fn (Builder $query) => $this->filterByProgram($query, (string) $request->validated('program_id')));
        $feeSchedulePage = $feeScheduleQuery->paginate();

        return response()->json([
            'fee_schedules' => FeeScheduleResource::collection($feeSchedulePage->getCollection()),
            'meta' => $this->buildPageMeta($feeSchedulePage),
        ]);
    }

    public function showFeeSchedule(int $id): JsonResponse
    {
        $feeSchedule = FeeSchedule::with(self::RELATIONS)->findOrFail($id);

        return response()->json([
            'fee_schedule' => FeeScheduleDetailResource::make($feeSchedule),
        ]);
    }

    public function createFeeSchedule(StoreFeeScheduleRequest $request): JsonResponse
    {
        $feeSchedule = DB::transaction(fn () => $this->createWithinLock($request), FeeSchedule::TRANSACTION_ATTEMPTS);

        return response()->json([
            'fee_schedule' => FeeScheduleDetailResource::make($feeSchedule),
        ], Response::HTTP_CREATED);
    }

    public function updateFeeSchedule(UpdateFeeScheduleRequest $request, int $id): JsonResponse
    {
        $feeSchedule = DB::transaction(fn () => $this->replaceLines($request, $id), FeeSchedule::TRANSACTION_ATTEMPTS);

        return response()->json([
            'fee_schedule' => FeeScheduleDetailResource::make($feeSchedule),
        ]);
    }

    public function deleteFeeSchedule(int $id): Response
    {
        FeeSchedule::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function lookupFeeSchedule(LookupFeeScheduleRequest $request): JsonResponse
    {
        $feeSchedule = (new FeeSchedule($request->validated()))->findEffective()?->load(self::RELATIONS);

        return response()->json([
            'fee_schedule' => $feeSchedule ? FeeScheduleDetailResource::make($feeSchedule) : null,
        ]);
    }

    protected function getSearchColumns(): array
    {
        return [];
    }

    private function filterByProgram(Builder $feeScheduleQuery, string $programFilter): void
    {
        if ($programFilter === ListFeeSchedulesRequest::NO_PROGRAM) {
            $feeScheduleQuery->whereNull('fee_schedules.program_id');

            return;
        }

        $feeScheduleQuery->where('fee_schedules.program_id', $programFilter);
    }

    private function createWithinLock(StoreFeeScheduleRequest $request): FeeSchedule
    {
        $candidate = $request->buildKeyCandidate();
        // every insert for a school year serializes on this row, so two requests cannot both pass the key check
        SchoolYear::whereKey($candidate->school_year_id)->lockForUpdate()->first();

        if (FeeSchedule::withSameKey($candidate)->exists()) {
            throw ValidationException::withMessages(['grade_level_id' => StoreFeeScheduleRequest::KEY_TAKEN_MESSAGE]);
        }

        $feeSchedule = FeeSchedule::create($candidate->only(FeeSchedule::KEY_FIELDS));
        $feeSchedule->lines()->createMany(FeeScheduleLine::buildRows($request->validated('lines')));

        return $feeSchedule->load(self::RELATIONS);
    }

    private function replaceLines(UpdateFeeScheduleRequest $request, int $id): FeeSchedule
    {
        $feeSchedule = FeeSchedule::lockForUpdate()->findOrFail($id);
        FeeScheduleLine::where('fee_schedule_id', $id)->delete();
        $feeSchedule->lines()->createMany(FeeScheduleLine::buildRows($request->validated('lines')));
        $feeSchedule->touch();

        return $feeSchedule->load(self::RELATIONS);
    }
}
