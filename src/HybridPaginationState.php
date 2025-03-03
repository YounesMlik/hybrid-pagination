<?php

namespace YounesMlik\HybridPagination;

use Illuminate\Pagination\Cursor;

class HybridPaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public static function resolveUsing($app)
    {
        HybridPaginator::viewFactoryResolver(fn() => $app['view']);

        HybridPaginator::currentPathResolver(fn() => $app['request']->url());

        HybridPaginator::currentPageResolver(function ($pageName = 'page') use ($app) {
            $page = $app['request']->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        HybridPaginator::queryStringResolver(fn() => $app['request']->query());

        HybridPaginator::prevPageResolver(function ($prevPageName = 'prev') use ($app) {
            $prevPage = $app['request']->input($prevPageName);
            if (filter_var($prevPage, FILTER_VALIDATE_INT) !== false && (int) $prevPage >= 1) {
                return (int) $prevPage;
            }

            return null;
        });

        HybridPaginator::currentCursorResolver(function ($cursorName = 'cursor') use ($app) {
            return Cursor::fromEncoded($app['request']->input($cursorName));
        });
    }
}
