<?php

namespace App\Http\Resources;

use App\Models\FeeScheduleLine;
use Illuminate\Http\Request;

class FeeScheduleDetailResource extends FeeScheduleResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'lines' => $this->lines->map(fn (FeeScheduleLine $line) => $this->buildLineRow($line))->all(),
        ];
    }

    private function buildLineRow(FeeScheduleLine $line): array
    {
        return [
            'id' => $line->id,
            'fee_item_id' => $line->fee_item_id,
            'amount' => $line->amount,
            'fee_item' => [
                'id' => $line->feeItem->id,
                'name' => $line->feeItem->name,
                'category' => $line->feeItem->category,
                'is_active' => $line->feeItem->is_active,
            ],
        ];
    }
}
