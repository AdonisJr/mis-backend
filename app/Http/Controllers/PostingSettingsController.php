<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePostingMappingsRequest;
use App\Http\Resources\PostingMappingResource;
use App\Models\Account;
use App\Models\PostingMapping;
use App\Support\JournalPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostingSettingsController extends Controller
{
    public function showPostingMappings(): JsonResponse
    {
        return $this->buildMappingsResponse();
    }

    public function updatePostingMappings(UpdatePostingMappingsRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->saveMappings($request->validated('mappings')), PostingMapping::TRANSACTION_ATTEMPTS);

        return $this->buildMappingsResponse();
    }

    private function buildMappingsResponse(): JsonResponse
    {
        return response()->json([
            'posting_mappings' => PostingMappingResource::collection(PostingMapping::buildCatalogRows()),
        ]);
    }

    /** @param  array<string, int|string>  $accountIdsByPurpose */
    private function saveMappings(array $accountIdsByPurpose): void
    {
        // locked so a concurrent deactivate cannot slip in between the check and the save
        $lockedAccounts = Account::whereIn('id', array_values($accountIdsByPurpose))->lockForUpdate()->get()->keyBy('id');
        $this->rejectInactiveAccounts($accountIdsByPurpose, $lockedAccounts);

        foreach ($accountIdsByPurpose as $purpose => $accountId) {
            PostingMapping::updateOrCreate(['purpose' => $purpose], ['account_id' => (int) $accountId]);
        }
    }

    /**
     * @param  array<string, int|string>  $accountIdsByPurpose
     * @param  Collection<int, Account>  $lockedAccounts
     */
    private function rejectInactiveAccounts(array $accountIdsByPurpose, Collection $lockedAccounts): void
    {
        $inactiveMessages = [];

        foreach ($accountIdsByPurpose as $purpose => $accountId) {
            $isActive = (bool) $lockedAccounts->get((int) $accountId)?->is_active;

            if (! $isActive) {
                $inactiveMessages['mappings.'.$purpose] = JournalPosting::ACCOUNT_INACTIVE_MESSAGE;
            }
        }

        if ($inactiveMessages !== []) {
            throw ValidationException::withMessages($inactiveMessages);
        }
    }
}
