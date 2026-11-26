<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalPosting
{
    public const ENTRY_ERROR_KEY = 'entry';

    public const ALREADY_POSTED_MESSAGE = 'The entry is already posted.';

    public const LINE_COUNT_MESSAGE = 'An entry needs 2 to 100 lines.';

    public const ACCOUNT_INACTIVE_MESSAGE = 'The selected account is inactive.';

    public const UNBALANCED_MESSAGE_FORMAT = 'Debits and credits must be equal and greater than zero. Debits: %s, credits: %s.';

    public const NOT_POSTED_MESSAGE = 'Only posted entries can be reversed.';

    public const ALREADY_REVERSED_MESSAGE = 'The entry has already been reversed.';

    public const REVERSAL_NOT_REVERSIBLE_MESSAGE = 'A reversal entry cannot be reversed.';

    public const REVERSAL_DESCRIPTION_FORMAT = 'Reversal of %s: %s';

    /** Posts a saved draft. Nests as a savepoint when the caller already holds a transaction. */
    public static function post(JournalEntry $entry, User $poster): JournalEntry
    {
        return DB::transaction(fn () => self::postWithinLock($entry->id, $poster), JournalEntry::TRANSACTION_ATTEMPTS);
    }

    /** Takes an unsaved reversal that carries reversal_of_id, saves it, posts it and links the original. */
    public static function reverse(JournalEntry $reversal, User $poster): JournalEntry
    {
        return DB::transaction(fn () => self::reverseWithinLock($reversal, $poster), JournalEntry::TRANSACTION_ATTEMPTS);
    }

    private static function postWithinLock(int $entryId, User $poster): JournalEntry
    {
        $entry = self::lockDraft($entryId);
        self::rejectBadLineCount($entry);
        self::rejectInactiveAccounts($entry);
        self::rejectUnbalanced($entry);

        return self::markPosted($entry, $poster);
    }

    private static function reverseWithinLock(JournalEntry $reversal, User $poster): JournalEntry
    {
        // lock order entry -> counter in both paths (post and reverse), so they cannot deadlock
        $original = self::lockReversible($reversal->reversal_of_id);
        $reversal->forceFill(['created_by' => $poster->id]);
        self::saveReversalDraft($reversal, $original);
        $postedReversal = self::postWithinLock($reversal->id, $poster);
        $original->forceFill(['reversed_by_id' => $postedReversal->id])->save();

        return $postedReversal;
    }

    private static function lockDraft(int $entryId): JournalEntry
    {
        // re-read under the lock: another tab may have posted this row meanwhile
        $entry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($entryId);

        if ($entry->isPosted()) {
            throw ValidationException::withMessages([self::ENTRY_ERROR_KEY => self::ALREADY_POSTED_MESSAGE]);
        }

        return $entry;
    }

    private static function rejectBadLineCount(JournalEntry $entry): void
    {
        $lineCount = $entry->lines->count();

        if ($lineCount >= JournalEntry::MIN_LINES && $lineCount <= JournalEntry::MAX_LINES) {
            return;
        }

        throw ValidationException::withMessages(['lines' => self::LINE_COUNT_MESSAGE]);
    }

    private static function rejectInactiveAccounts(JournalEntry $entry): void
    {
        // a reversal must post even when an account was deactivated after the original
        if ($entry->isReversal()) {
            return;
        }

        $inactiveIds = Account::whereIn('id', $entry->lines->pluck('account_id'))->where('is_active', false)->pluck('id');
        $inactiveLineIndex = $entry->lines->search(fn (JournalLine $line) => $inactiveIds->contains($line->account_id));

        if ($inactiveLineIndex === false) {
            return;
        }

        throw ValidationException::withMessages(['lines.'.$inactiveLineIndex.'.account_id' => self::ACCOUNT_INACTIVE_MESSAGE]);
    }

    private static function rejectUnbalanced(JournalEntry $entry): void
    {
        $debitTotal = $entry->calculateDebitTotal();
        $creditTotal = $entry->calculateCreditTotal();

        if ($debitTotal->isEqualTo($creditTotal) && $debitTotal->isPositive()) {
            return;
        }

        throw ValidationException::withMessages(['lines' => sprintf(self::UNBALANCED_MESSAGE_FORMAT, $debitTotal, $creditTotal)]);
    }

    private static function markPosted(JournalEntry $entry, User $poster): JournalEntry
    {
        // allocated last so a refused post never reaches the counter
        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'number' => JournalNumberAllocator::allocateNext(),
            'posted_by' => $poster->id,
            'posted_at' => now(),
        ])->save();

        return $entry->load(JournalEntry::DETAIL_RELATIONS);
    }

    private static function lockReversible(int $originalId): JournalEntry
    {
        $original = JournalEntry::with('lines')->lockForUpdate()->findOrFail($originalId);
        $refusal = match (true) {
            ! $original->isPosted() => self::NOT_POSTED_MESSAGE,
            $original->isReversal() => self::REVERSAL_NOT_REVERSIBLE_MESSAGE,
            $original->isReversed() => self::ALREADY_REVERSED_MESSAGE,
            default => null,
        };

        if ($refusal !== null) {
            throw ValidationException::withMessages([self::ENTRY_ERROR_KEY => $refusal]);
        }

        return $original;
    }

    private static function saveReversalDraft(JournalEntry $reversal, JournalEntry $original): void
    {
        $reversal->forceFill([
            'description' => sprintf(self::REVERSAL_DESCRIPTION_FORMAT, $original->number, $reversal->reversal_reason),
            'reference' => null,
        ])->save();
        $reversal->lines()->createMany(self::buildSwappedRows($original));
    }

    /** @return list<array<string, mixed>> */
    private static function buildSwappedRows(JournalEntry $original): array
    {
        return JournalLine::buildRows($original->lines->map(fn (JournalLine $line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'memo' => $line->memo,
        ]));
    }
}
