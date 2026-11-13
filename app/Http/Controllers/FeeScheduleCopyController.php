<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyFeeSchedulesRequest;
use App\Http\Resources\FeeScheduleResource;
use App\Models\FeeSchedule;
use App\Models\FeeScheduleLine;
use App\Models\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeeScheduleCopyController extends Controller
{
    public const SKIP_EXISTS_REASON = 'A fee schedule already exists in the target school year.';

    public const SKIP_PROGRAM_INACTIVE_REASON = 'The program is inactive.';

    public const SKIP_NO_ACTIVE_ITEMS_REASON = 'No active fee items.';

    private const NO_PROGRAM_KEY = 'none';

    public function copyFeeSchedules(CopyFeeSchedulesRequest $request): JsonResponse
    {
        $copyOutcome = DB::transaction(fn () => $this->copyWithinLock($request), FeeSchedule::TRANSACTION_ATTEMPTS);

        return response()->json($copyOutcome);
    }

    private function copyWithinLock(CopyFeeSchedulesRequest $request): array
    {
        $targetYearId = (int) $request->validated('target_school_year_id');
        // the same year row lock as a create, so a concurrent create cannot take a key between the check and the insert
        SchoolYear::whereKey($targetYearId)->lockForUpdate()->first();
        $takenKeys = $this->pluckTakenKeys($targetYearId);
        $sourceSchedules = FeeSchedule::query()->orderedForList()->with(FeeScheduleController::RELATIONS)->where('fee_schedules.school_year_id', $request->validated('source_school_year_id'))->get();
        $copiedCount = 0;
        $skippedRows = [];

        foreach ($sourceSchedules as $sourceSchedule) {
            $skipReason = $this->findSkipReason($sourceSchedule, $takenKeys);

            if ($skipReason !== null) {
                $skippedRows[] = $this->buildSkippedRow($sourceSchedule, $skipReason);

                continue;
            }

            $this->copySchedule($sourceSchedule, $targetYearId);
            $copiedCount++;
        }

        return ['copied_count' => $copiedCount, 'skipped' => $skippedRows];
    }

    private function pluckTakenKeys(int $targetYearId): Collection
    {
        return FeeSchedule::where('school_year_id', $targetYearId)->get()->map(fn (FeeSchedule $feeSchedule) => $this->buildGroupKey($feeSchedule));
    }

    private function buildGroupKey(FeeSchedule $feeSchedule): string
    {
        return $feeSchedule->grade_level_id.'-'.($feeSchedule->program_id ?? self::NO_PROGRAM_KEY);
    }

    private function findSkipReason(FeeSchedule $sourceSchedule, Collection $takenKeys): ?string
    {
        return match (true) {
            $takenKeys->contains($this->buildGroupKey($sourceSchedule)) => self::SKIP_EXISTS_REASON,
            $sourceSchedule->program && ! $sourceSchedule->program->is_active => self::SKIP_PROGRAM_INACTIVE_REASON,
            $this->listActiveLines($sourceSchedule)->isEmpty() => self::SKIP_NO_ACTIVE_ITEMS_REASON,
            default => null,
        };
    }

    private function listActiveLines(FeeSchedule $sourceSchedule): Collection
    {
        return $sourceSchedule->lines->filter(fn (FeeScheduleLine $line) => $line->feeItem->is_active);
    }

    private function copySchedule(FeeSchedule $sourceSchedule, int $targetYearId): void
    {
        $copiedSchedule = FeeSchedule::create([
            'school_year_id' => $targetYearId,
            'grade_level_id' => $sourceSchedule->grade_level_id,
            'program_id' => $sourceSchedule->program_id,
        ]);

        $copiedSchedule->lines()->createMany(FeeScheduleLine::buildRows($this->listActiveLines($sourceSchedule)));
    }

    private function buildSkippedRow(FeeSchedule $sourceSchedule, string $reason): array
    {
        return [...Arr::only(FeeScheduleResource::make($sourceSchedule)->resolve(), ['grade_level', 'program']), 'reason' => $reason];
    }
}
