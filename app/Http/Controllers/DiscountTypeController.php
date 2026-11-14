<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListDiscountTypesRequest;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\StoreDiscountTypeRequest;
use App\Http\Requests\UpdateDiscountTypeRequest;
use App\Http\Resources\DiscountTypeResource;
use App\Models\DiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DiscountTypeController extends Controller
{
    use SearchesPaginatedLists;

    public const HELD_DELETE_MESSAGE = 'The discount type is held by students and cannot be deleted. Deactivate it instead.';

    private const SEARCH_COLUMNS = ['name'];

    public function listDiscountTypes(ListDiscountTypesRequest $request): JsonResponse
    {
        $discountTypeQuery = DiscountType::query()->orderBy('name')->orderBy('id');
        $this->applySearch($discountTypeQuery, (string) $request->validated('search'));
        $discountTypeQuery->when($request->filled('source'), fn (Builder $query) => $query->where('source', $request->validated('source')));
        $discountTypeQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $discountTypePage = $discountTypeQuery->paginate();

        return response()->json([
            'discount_types' => DiscountTypeResource::collection($discountTypePage->getCollection()),
            'meta' => $this->buildPageMeta($discountTypePage),
        ]);
    }

    public function showDiscountType(int $id): JsonResponse
    {
        return response()->json([
            'discount_type' => DiscountTypeResource::make(DiscountType::findOrFail($id)),
        ]);
    }

    public function createDiscountType(StoreDiscountTypeRequest $request): JsonResponse
    {
        $discountType = DiscountType::create($request->buildTypeAttributes());

        return response()->json([
            'discount_type' => DiscountTypeResource::make($discountType),
        ], Response::HTTP_CREATED);
    }

    public function updateDiscountType(UpdateDiscountTypeRequest $request, int $id): JsonResponse
    {
        $discountType = DiscountType::findOrFail($id);
        $discountType->update($request->buildTypeAttributes());

        return response()->json([
            'discount_type' => DiscountTypeResource::make($discountType),
        ]);
    }

    public function deactivateDiscountType(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivateDiscountType(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    public function deleteDiscountType(int $id): Response
    {
        DiscountType::findOrFail($id);
        $deletedCount = DiscountType::whereKey($id)->whereDoesntHave('studentDiscounts')->delete();

        // the conditional delete is the guard, so an assignment landing between a check and the delete cannot slip through
        if ($deletedCount === 0) {
            return response()->json(['message' => self::HELD_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $discountType = DiscountType::findOrFail($id);
        $discountType->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'discount_type' => DiscountTypeResource::make($discountType),
        ]);
    }
}
