<?php

namespace App\Http\Controllers;

use App\Http\Requests\CopyCurriculumRequest;
use App\Http\Resources\CurriculumEntryResource;
use App\Models\CurriculumEntry;
use App\Models\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurriculumCopyController extends Controller
{
    public const SKIP_SUBJECT_INACTIVE_REASON = 'The subject is inactive.';

    public const SKIP_PROGRAM_INACTIVE_REASON = 'The program is inactive.';

    public const SKIP_TERM_MISSING_REASON = 'The term number does not exist in the target school year.';

    private const SKIPPED_ROW_KEYS = ['subject', 'grade_level', 'program', 'term_number'];

    public function copyEntries(CopyCurriculumRequest $request): JsonResponse
    {
        $copyOutcome = DB::transaction(fn () => $this->copyWithinLock($request), CurriculumEntry::TRANSACTION_ATTEMPTS);

        return response()->json($copyOutcome);
    }

    /** @return array{copied_count: int, skipped_count: int, skipped: list<array<string, mixed>>} */
    private function copyWithinLock(CopyCurriculumRequest $request): array
    {
        // the same year row lock as an add, so a concurrent add cannot slip into the target between the check and the inserts
        $targetYear = SchoolYear::whereKey($request->validated('target_school_year_id'))->lockForUpdate()->firstOrFail();

        if ($targetYear->curriculumEntries()->exists()) {
            throw ValidationException::withMessages(['target_school_year_id' => CopyCurriculumRequest::TARGET_NOT_EMPTY_MESSAGE]);
        }

        $sourceEntries = CurriculumEntry::query()->orderedForList()->with(CurriculumEntryController::RELATIONS)->where('curriculum_entries.school_year_id', $request->validated('source_school_year_id'))->get();
        $copiedCount = 0;
        $skippedRows = [];

        foreach ($sourceEntries as $sourceEntry) {
            $skipReason = $this->findSkipReason($sourceEntry, $targetYear);

            if ($skipReason !== null) {
                $skippedRows[] = $this->buildSkippedRow($sourceEntry, $skipReason);

                continue;
            }

            $this->copyEntry($sourceEntry, $targetYear->id);
            $copiedCount++;
        }

        return ['copied_count' => $copiedCount, 'skipped_count' => count($skippedRows), 'skipped' => $skippedRows];
    }

    private function findSkipReason(CurriculumEntry $sourceEntry, SchoolYear $targetYear): ?string
    {
        return match (true) {
            ! $sourceEntry->subject->is_active => self::SKIP_SUBJECT_INACTIVE_REASON,
            $sourceEntry->program && ! $sourceEntry->program->is_active => self::SKIP_PROGRAM_INACTIVE_REASON,
            $sourceEntry->term_number !== null && $sourceEntry->term_number > SchoolYear::TERM_COUNTS[$targetYear->term_type] => self::SKIP_TERM_MISSING_REASON,
            default => null,
        };
    }

    private function copyEntry(CurriculumEntry $sourceEntry, int $targetYearId): void
    {
        CurriculumEntry::create([
            'school_year_id' => $targetYearId,
            'grade_level_id' => $sourceEntry->grade_level_id,
            'subject_id' => $sourceEntry->subject_id,
            'program_id' => $sourceEntry->program_id,
            'term_number' => $sourceEntry->term_number,
            'sort_order' => $sourceEntry->sort_order,
        ]);
    }

    /** @return array<string, mixed> */
    private function buildSkippedRow(CurriculumEntry $sourceEntry, string $reason): array
    {
        return [...Arr::only(CurriculumEntryResource::make($sourceEntry)->resolve(), self::SKIPPED_ROW_KEYS), 'reason' => $reason];
    }
}
