<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps one catalog row built by PostingMapping::buildCatalogRows(). */
class PostingMappingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'purpose' => $this->resource['purpose'],
            'label' => $this->resource['label'],
            'group' => $this->resource['group'],
            'account_type' => $this->resource['account_type'],
            'account' => $this->buildAccountSummary($this->resource['account']),
        ];
    }

    /** @return array{id: int, code: string, name: string, is_active: bool}|null */
    private function buildAccountSummary(?Account $account): ?array
    {
        return $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'is_active' => $account->is_active] : null;
    }
}
