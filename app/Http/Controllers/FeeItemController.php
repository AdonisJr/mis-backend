<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListFeeItemsRequest;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\StoreFeeItemRequest;
use App\Http\Requests\UpdateFeeItemRequest;
use App\Http\Resources\FeeItemResource;
use App\Models\FeeItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class FeeItemController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['name'];

    public function listFeeItems(ListFeeItemsRequest $request): JsonResponse
    {
        $feeItemQuery = FeeItem::query()->orderBy('name')->orderBy('id');
        $this->applySearch($feeItemQuery, (string) $request->validated('search'));
        $feeItemQuery->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->validated('category')));
        $feeItemQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $feeItemPage = $feeItemQuery->paginate();

        return response()->json([
            'fee_items' => FeeItemResource::collection($feeItemPage->getCollection()),
            'meta' => $this->buildPageMeta($feeItemPage),
        ]);
    }

    public function createFeeItem(StoreFeeItemRequest $request): JsonResponse
    {
        $feeItem = FeeItem::create($request->validated());

        return response()->json([
            'fee_item' => FeeItemResource::make($feeItem),
        ], Response::HTTP_CREATED);
    }

    public function updateFeeItem(UpdateFeeItemRequest $request, int $id): JsonResponse
    {
        $feeItem = FeeItem::findOrFail($id);
        $feeItem->update($request->validated());

        return response()->json([
            'fee_item' => FeeItemResource::make($feeItem),
        ]);
    }

    public function deactivateFeeItem(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivateFeeItem(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $feeItem = FeeItem::findOrFail($id);
        $feeItem->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'fee_item' => FeeItemResource::make($feeItem),
        ]);
    }
}
