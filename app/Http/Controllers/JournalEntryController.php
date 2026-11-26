<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListJournalEntriesRequest;
use App\Http\Requests\ReverseJournalEntryRequest;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\JournalEntryRowResource;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\JournalPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class JournalEntryController extends Controller
{
    use SearchesPaginatedLists;

    public const POSTED_LOCKED_MESSAGE = 'Posted entries cannot be changed. Reverse it instead.';

    public const BILLING_LOCKED_MESSAGE = 'Billing entries are reversed by voiding the payment or re-assessing.';

    private const SEARCH_COLUMNS = ['number', 'description', 'reference'];

    private const HEADER_FIELDS = ['entry_date', 'description', 'reference'];

    public function listJournalEntries(ListJournalEntriesRequest $request): JsonResponse
    {
        $entryPage = $this->buildListQuery($request)->paginate();

        return response()->json([
            'journal_entries' => JournalEntryRowResource::collection($entryPage->getCollection()),
            'meta' => $this->buildPageMeta($entryPage),
        ]);
    }

    public function showJournalEntry(int $id): JsonResponse
    {
        return response()->json([
            'journal_entry' => JournalEntryResource::make(JournalEntry::with(JournalEntry::DETAIL_RELATIONS)->findOrFail($id)),
        ]);
    }

    public function createJournalEntry(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = DB::transaction(fn () => $this->saveDraft($request), JournalEntry::TRANSACTION_ATTEMPTS);

        return response()->json([
            'journal_entry' => JournalEntryResource::make($entry),
        ], Response::HTTP_CREATED);
    }

    public function updateJournalEntry(UpdateJournalEntryRequest $request, int $id): JsonResponse
    {
        $entry = DB::transaction(fn () => $this->replaceDraft($request, $id), JournalEntry::TRANSACTION_ATTEMPTS);

        return response()->json([
            'journal_entry' => JournalEntryResource::make($entry),
        ]);
    }

    public function deleteJournalEntry(int $id): Response
    {
        DB::transaction(fn () => $this->lockEditableDraft($id)->delete(), JournalEntry::TRANSACTION_ATTEMPTS);

        return response()->noContent();
    }

    public function postJournalEntry(Request $request, int $id): JsonResponse
    {
        $postedEntry = JournalPosting::post(JournalEntry::findOrFail($id), $request->user());

        return response()->json([
            'journal_entry' => JournalEntryResource::make($postedEntry),
        ]);
    }

    public function reverseJournalEntry(ReverseJournalEntryRequest $request, int $id): JsonResponse
    {
        $original = JournalEntry::findOrFail($id);
        $this->rejectBillingEntry($original);
        $reversal = new JournalEntry;
        $reversal->forceFill([
            'entry_date' => $request->validated('date'),
            'reversal_of_id' => $original->id,
            'reversal_reason' => $request->validated('reason'),
            'source' => JournalEntry::SOURCE_MANUAL,
        ]);
        $postedReversal = JournalPosting::reverse($reversal, $request->user());

        return response()->json([
            'journal_entry' => JournalEntryResource::make($postedReversal),
        ], Response::HTTP_CREATED);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function buildListQuery(ListJournalEntriesRequest $request): Builder
    {
        $listQuery = JournalEntry::query()
            ->with(JournalEntry::ROW_RELATIONS)
            ->when($request->filled('status'), fn (Builder $query) => $query->withDisplayStatus($request->validated('status')))
            // whereDate: the stored date may carry a time part, which would drop the inclusive end day
            ->when($request->filled('source'), fn (Builder $query) => $query->where('source', $request->validated('source')))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('entry_date', '>=', $request->validated('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('entry_date', '<=', $request->validated('to')))
            ->when($request->filled('account_id'), fn (Builder $query) => $query->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->where('account_id', $request->validated('account_id'))))
            ->orderByDesc('entry_date')
            ->orderByDesc('id');
        $this->applySearch($listQuery, (string) $request->validated('search'));

        return $listQuery;
    }

    private function saveDraft(StoreJournalEntryRequest $request): JournalEntry
    {
        $entry = new JournalEntry($request->safe()->only(self::HEADER_FIELDS));
        $entry->forceFill(['created_by' => $request->user()->id])->save();
        $entry->lines()->createMany(JournalLine::buildRows($request->validated('lines')));

        return $entry->load(JournalEntry::DETAIL_RELATIONS);
    }

    private function replaceDraft(UpdateJournalEntryRequest $request, int $id): JournalEntry
    {
        $entry = $this->lockEditableDraft($id);
        $entry->update($request->safe()->only(self::HEADER_FIELDS));
        JournalLine::where('journal_entry_id', $id)->delete();
        $entry->lines()->createMany(JournalLine::buildRows($request->validated('lines')));
        $entry->touch();

        return $entry->load(JournalEntry::DETAIL_RELATIONS);
    }

    private function lockEditableDraft(int $id): JournalEntry
    {
        // re-read under the lock: another tab may have posted this row meanwhile
        $entry = JournalEntry::lockForUpdate()->findOrFail($id);
        $this->rejectBillingEntry($entry);

        if ($entry->isPosted()) {
            throw ValidationException::withMessages([JournalPosting::ENTRY_ERROR_KEY => self::POSTED_LOCKED_MESSAGE]);
        }

        return $entry;
    }

    private function rejectBillingEntry(JournalEntry $entry): void
    {
        if (! $entry->isBillingSourced()) {
            return;
        }

        throw ValidationException::withMessages([JournalPosting::ENTRY_ERROR_KEY => self::BILLING_LOCKED_MESSAGE]);
    }
}
