<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

trait SearchesPaginatedLists
{
    abstract protected function getSearchColumns(): array;

    protected function applySearch(Builder $listQuery, string $search): void
    {
        // no `u` flag: mb_strtolower already replaced invalid UTF-8, and preg_split with `u` would return false on it
        $searchWords = preg_split('/\s+/', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($searchWords as $searchWord) {
            $this->applySearchWord($listQuery, $searchWord);
        }
    }

    private function applySearchWord(Builder $listQuery, string $searchWord): void
    {
        $searchColumns = $this->getSearchColumns();

        $listQuery->where(function (Builder $wordQuery) use ($searchColumns, $searchWord) {
            foreach ($searchColumns as $column) {
                // LOWER on both sides keeps the match case-insensitive on SQLite, MySQL and PostgreSQL alike
                $wordQuery->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.$searchWord.'%']);
            }
        });
    }

    protected function buildPageMeta(LengthAwarePaginator $listPage): array
    {
        return [
            'current_page' => $listPage->currentPage(),
            'last_page' => $listPage->lastPage(),
            'per_page' => $listPage->perPage(),
            'total' => $listPage->total(),
        ];
    }
}
