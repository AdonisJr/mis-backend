<?php

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalEntry */
class JournalEntryRowResource extends JsonResource
{
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
            'source' => $this->source,
            'source_link' => $this->buildSourceLink(),
            'debit_total' => (string) $this->calculateDebitTotal(),
            'credit_total' => (string) $this->calculateCreditTotal(),
        ];
    }
}
