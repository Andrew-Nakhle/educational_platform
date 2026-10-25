<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * `meta` for the paginated `{ message, data, meta }` responses.
     *
     * @return array{current_page: int, last_page: int, total: int, per_page: int}
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ];
    }

    /**
     * Sets sort_order 1..n on $children in the order of $ids. $ids must
     * be exactly the ids of $children (each once), otherwise 422.
     *
     * @param  array<int, int>  $ids
     */
    protected function applySortOrder(Collection $children, array $ids, string $message): void
    {
        $ids = array_map('intval', $ids);
        $childIds = $children->modelKeys();

        sort($childIds);
        $sorted = $ids;
        sort($sorted);

        if ($sorted !== array_map('intval', $childIds)) {
            throw ValidationException::withMessages(['ids' => [$message]]);
        }

        $byId = $children->keyBy('id');

        DB::transaction(function () use ($ids, $byId) {
            foreach ($ids as $index => $id) {
                $byId[$id]->update(['sort_order' => $index + 1]);
            }
        });
    }
}
