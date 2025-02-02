<?php

namespace YounesMlik\HybridPagination;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use RuntimeException;


trait HybridPaginate
{
    /**
     * Paginate the given query into a hybrid paginator.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  string  $prevPageName
     * @param  int|null  $prevPage
     * @param  string  $cursorName
     * @param  \Illuminate\Pagination\Cursor|string|null  $cursor
     * @param  \Closure|int|null  $total
     * @return HybridPaginator
     */
    public function scopeHybridPaginate(
        $query,
        $perPage = null,
        $columns = ['*'],
        $pageName = 'page',
        $page = null,
        $prevPageName = 'prev',
        $prevPage = null,
        $cursorName = 'cursor',
        $cursor = null,
        $total = null,
    ) {
        $page = $page ?: HybridPaginator::resolveCurrentPage($pageName, $page);
        $prevPage = $prevPage ?: HybridPaginator::resolvePrevPage($prevPageName, $prevPage);

        $total = value($total) ?? $query->count();

        $perPage = value($perPage, $total) ?: $this->getPerPage();

        if (!$cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : HybridPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        $temp_cursor = $query->cursorPaginate(
            $perPage - 1,
            $columns,
            $cursorName,
            $cursor,
        );
        dump($query->toRawSql());

        if ($prevPage !== null && $prevPage !== $page) {
            $query
                // ->where()
                ->offset((abs($page - $prevPage) - 1) * $perPage);
        }

        $results = $query->get($columns);

        return new HybridPaginator(
            $results,
            $total,
            $perPage,
            $page,
            $prevPage,
            $cursor,
            [
                'path' => HybridPaginator::resolveCurrentPath(),
                'pageName' => $pageName,
                'prevPageName' => $prevPageName,
                'cursorName' => $cursorName,
                'parameters' => $temp_cursor->getOptions()["parameters"],
            ]
        );
    }
}