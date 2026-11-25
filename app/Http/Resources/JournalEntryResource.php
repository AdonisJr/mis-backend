<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BuildsAssessmentSummaries;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalEntry */
class JournalEntryResource extends JsonResource
{
    use BuildsAssessmentSummaries;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'entry_date' => $this->entry_date->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'display_status' => $this->getDisplayStatus(),
            'can_reverse' => $this->canBeReversed(),
            'source' => $this->source,
            'created_by' => $this->buildUserSummary($this->creator),
            'posted_by' => $this->buildUserSummary($this->poster),
            'posted_at' => $this->posted_at,
            'reversal_of' => $this->buildLinkSummary($this->reversalOf),
            'reversed_by' => $this->buildLinkSummary($this->reversedBy),
            'reversal_reason' => $this->reversal_reason,
            'debit_total' => (string) $this->calculateDebitTotal(),
            'credit_total' => (string) $this->calculateCreditTotal(),
            'lines' => $this->lines->map(fn (JournalLine $line) => $this->buildLineRow($line))->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return array{id: int, number: string|null}|null */
    private function buildLinkSummary(?JournalEntry $linked): ?array
    {
        return $linked ? ['id' => $linked->id, 'number' => $linked->number] : null;
    }

    /** @return array<string, mixed> */
    private function buildLineRow(JournalLine $line): array
    {
        return [
            'id' => $line->id,
            'account' => ['id' => $line->account->id, 'code' => $line->account->code, 'name' => $line->account->name],
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $line->memo,
        ];
    }
}
