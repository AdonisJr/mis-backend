<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListAccountsRequest;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\PostingMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    use SearchesPaginatedLists;

    public const USED_DELETE_MESSAGE = 'The account is in use and cannot be deleted. Deactivate it instead.';

    public const MAPPED_MESSAGE_FORMAT = 'The account is mapped to %s. Re-map it in Posting settings first.';

    private const SEARCH_COLUMNS = ['code', 'name'];

    public function listAccounts(ListAccountsRequest $request): JsonResponse
    {
        $accountQuery = Account::query()->withExists('journalLines')->orderBy('code')->orderBy('id');
        $this->applySearch($accountQuery, (string) $request->validated('search'));
        $accountQuery->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->validated('type')));
        $accountQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $accountPage = $accountQuery->paginate();

        return response()->json([
            'accounts' => AccountResource::collection($accountPage->getCollection()),
            'meta' => $this->buildPageMeta($accountPage),
        ]);
    }

    public function showAccount(int $id): JsonResponse
    {
        return response()->json([
            'account' => AccountResource::make(Account::findOrFail($id)),
        ]);
    }

    public function createAccount(StoreAccountRequest $request): JsonResponse
    {
        $account = Account::create($request->validated());

        return response()->json([
            'account' => AccountResource::make($account),
        ], Response::HTTP_CREATED);
    }

    public function updateAccount(UpdateAccountRequest $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $account->update($request->validated());

        return response()->json([
            'account' => AccountResource::make($account),
        ]);
    }

    public function deactivateAccount(int $id): JsonResponse
    {
        return DB::transaction(fn () => $this->deactivateWithinLock($id), PostingMapping::TRANSACTION_ATTEMPTS);
    }

    public function reactivateAccount(int $id): JsonResponse
    {
        return $this->saveActiveState(Account::findOrFail($id), true);
    }

    public function deleteAccount(int $id): Response|JsonResponse
    {
        $account = Account::findOrFail($id);
        $mappedRefusal = $this->buildMappedRefusal($account);

        if ($mappedRefusal !== null) {
            return $mappedRefusal;
        }

        if ($account->isUsed()) {
            return response()->json(['message' => self::USED_DELETE_MESSAGE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $account->delete();

        return response()->noContent();
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function deactivateWithinLock(int $id): JsonResponse
    {
        // locked so a concurrent mapping update cannot map the account between the check and the save
        $account = Account::lockForUpdate()->findOrFail($id);

        return $this->buildMappedRefusal($account) ?? $this->saveActiveState($account, false);
    }

    private function buildMappedRefusal(Account $account): ?JsonResponse
    {
        $mappedLabels = $account->listMappedLabels();

        if ($mappedLabels === []) {
            return null;
        }

        return response()->json(['message' => sprintf(self::MAPPED_MESSAGE_FORMAT, implode(', ', $mappedLabels))], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function saveActiveState(Account $account, bool $isActive): JsonResponse
    {
        $account->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'account' => AccountResource::make($account),
        ]);
    }
}
