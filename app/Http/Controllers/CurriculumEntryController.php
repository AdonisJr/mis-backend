<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListCurriculumRequest;
use App\Http\Requests\StoreCurriculumEntryRequest;
use App\Http\Requests\UpdateCurriculumEntryRequest;
use App\Http\Resources\CurriculumEntryResource;
use App\Models\CurriculumEntry;
use App\Models\SchoolYear;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CurriculumEntryController extends Controller
{
    public const RELATIONS = ['subject', 'gradeLevel', 'program'];

    public const CLASSES_DELETE_MESSAGE = 'The curriculum entry is used by a class and cannot be deleted.';

    private const HOURS_SCALE = 1;

    public function listEntries(ListCurriculumRequest $request): JsonResponse
    {
        $entryQuery = CurriculumEntry::query()->orderedForList()->with(self::RELATIONS)->where('curriculum_entries.school_year_id', $request->validated('school_year_id'));
        $entryQuery->when($request->filled('grade_level_id'), fn (Builder $query) => $query->where('curriculum_entries.grade_level_id', $request->validated('grade_level_id')));
        $entryQuery->when($request->filled('program_id'), fn (Builder $query) => $query->where('curriculum_entries.program_id', $request->validated('program_id')));
        $entryQuery->when($request->filled('term_number'), fn (Builder $query) => $query->where('curriculum_entries.term_number', $request->validated('term_number')));
        $entries = $entryQuery->get();

        return response()->json([
            'curriculum_entries' => CurriculumEntryResource::collection($entries),
            'group_totals' => $this->summarizeGroupTotals($entries),
        ]);
    }

    public function createEntry(StoreCurriculumEntryRequest $request): JsonResponse
    {
        $entry = DB::transaction(fn () => $this->createWithinLock($request), CurriculumEntry::TRANSACTION_ATTEMPTS);

        return response()->json([
            'curriculum_entry' => CurriculumEntryResource::make($entry),
        ], Response::HTTP_CREATED);
    }

    public function updateEntry(UpdateCurriculumEntryRequest $request, int $id): JsonResponse
    {
        $entry = CurriculumEntry::findOrFail($id);
        $entry->update($request->validated());

        return response()->json([
            'curriculum_entry' => CurriculumEntryResource::make($entry->load(self::RELATIONS)),
        ]);
    }

    public function deleteEntry(int $id): Response
    {
        $entry = CurriculumEntry::findOrFail($id);
        $isDeleted = DB::transaction(fn () => $this->deleteUnlessScheduled($entry), CurriculumEntry::TRANSACTION_ATTEMPTS);

        if (! $isDeleted) {
            return response()->json(['message' => self::CLASSES_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    private function deleteUnlessScheduled(CurriculumEntry $entry): bool
    {
        // every class save of a school year serializes on this row, so a class cannot be added while the entry is deleted
        SchoolYear::whereKey($entry->school_year_id)->lockForUpdate()->first();

        if ($entry->schoolClasses()->exists()) {
            return false;
        }

        return (bool) $entry->delete();
    }

    private function createWithinLock(StoreCurriculumEntryRequest $request): CurriculumEntry
    {
        $candidate = $request->buildKeyCandidate();
        // every insert for a school year serializes on this row, so two requests cannot both pass the key check
        SchoolYear::whereKey($candidate->school_year_id)->lockForUpdate()->first();

        if (CurriculumEntry::withSameKey($candidate)->exists()) {
            throw ValidationException::withMessages(['subject_id' => StoreCurriculumEntryRequest::KEY_TAKEN_MESSAGE]);
        }

        return CurriculumEntry::create($request->validated())->load(self::RELATIONS);
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function summarizeGroupTotals(Collection $entries): array
    {
        return $entries->groupBy(fn (CurriculumEntry $entry) => $entry->buildGroupKey())
            ->values()
            ->map(fn (Collection $groupEntries) => $this->buildGroupTotal($groupEntries))
            ->all();
    }

    /**
     * @param  Collection<int, CurriculumEntry>  $groupEntries
     * @return array<string, mixed>
     */
    private function buildGroupTotal(Collection $groupEntries): array
    {
        $weeklyHoursTotal = BigDecimal::zero();

        foreach ($groupEntries as $groupEntry) {
            $weeklyHoursTotal = $weeklyHoursTotal->plus($groupEntry->subject->weekly_hours);
        }

        $firstEntry = $groupEntries->first();

        return [
            'grade_level_id' => $firstEntry->grade_level_id,
            'program_id' => $firstEntry->program_id,
            'term_number' => $firstEntry->term_number,
            'entries_count' => $groupEntries->count(),
            'weekly_hours_total' => (string) $weeklyHoursTotal->toScale(self::HOURS_SCALE),
        ];
    }
}
