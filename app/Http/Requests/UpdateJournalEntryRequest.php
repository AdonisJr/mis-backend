<?php

namespace App\Http\Requests;

use App\Models\JournalEntry;

class UpdateJournalEntryRequest extends StoreJournalEntryRequest
{
    private ?JournalEntry $storedEntry = null;

    private bool $isStoredEntryLoaded = false;

    protected function isLineUnchanged(array $line): bool
    {
        // a missing entry leaves this null and the controller answers 404
        $storedEntry = $this->findStoredEntry();

        // an inactive account may stay only when the stored draft already has a line on it
        return $storedEntry !== null && $storedEntry->lines->contains('account_id', (int) $line['account_id']);
    }

    private function findStoredEntry(): ?JournalEntry
    {
        if (! $this->isStoredEntryLoaded) {
            $this->storedEntry = JournalEntry::with('lines')->find($this->route('id'));
            $this->isStoredEntryLoaded = true;
        }

        return $this->storedEntry;
    }
}
