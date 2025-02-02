<?php

namespace YounesMlik\HybridPagination;

use ArrayAccess;
use Countable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\UrlWindow;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends AbstractHybridPaginator<TKey, TValue>
 *
 * @implements Arrayable<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 * @implements LengthAwarePaginatorContract<TKey, TValue>
 */
class HybridPaginator extends AbstractHybridPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, LengthAwarePaginatorContract
{
    /**
     * The total number of items before slicing.
     *
     * @var int
     */
    protected $total;

    /**
     * The last available page.
     *
     * @var int
     */
    protected $lastPage;

    /**
     * Create a new paginator instance.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @param  int  $total
     * @param  int  $perPage
     * @param  int|null  $currentPage
     * @param  int|null  $prevPage
     * @param  \Illuminate\Pagination\Cursor|string|null  $cursor
     * @param  array  $options  (path, query, fragment, pageName, prevPageName, cursorName)
     * @return void
     */
    public function __construct(
        $items,
        $total,
        $perPage,
        $currentPage = null,
        $prevPage = null,
        $cursor = null,
        array $options = [],
    ) {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->total = $total;
        $this->perPage = (int) $perPage;
        $this->lastPage = max((int) ceil($total / $perPage), 1);
        $this->path = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;
        $this->currentPage = $this->setCurrentPage($currentPage, $this->pageName);
        $this->prevPage = $this->setPrevPage($prevPage, $this->prevPageName);
        $this->cursor = $this->setCursor($cursor, $this->cursorName);
        $this->setItems($items);
    }

    /**
     * Set the items for the paginator.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @return void
     */
    protected function setItems($items)
    {
        $this->items = $items instanceof Collection ? $items : Collection::make($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);

        if ($this->isBackwards()) {
            $this->items = $this->items->reverse()->values();
        }
    }

    /**
     * Determine if the paginator is backwards.
     *
     * @return bool
     */
    protected function isBackwards()
    {
        if (!is_null($this->prevPage) && $this->currentPage < $this->prevPage) {
            return true;
        } else {
            return false;
        }

        // if (!is_null($this->cursor) && $this->cursor->pointsToPreviousItems()) {
        //     return true;
        // } else {
        //     return false;
        // }
    }

    /**
     * Get the current page for the request.
     *
     * @param  int  $currentPage
     * @param  string  $pageName
     * @return int
     */
    protected function setCurrentPage($currentPage, $pageName)
    {
        $currentPage = $currentPage ?: static::resolveCurrentPage($pageName);

        return $this->isValidPageNumber($currentPage) ? (int) $currentPage : 1;
    }

    /**
     * Get the previous page for the request.
     *
     * @param  int  $prevPage
     * @param  string  $prevPageName
     * @return int|null
     */
    protected function setPrevPage($prevPage, $prevPageName)
    {
        $prevPage = $prevPage ?: static::resolvePrevPage($prevPageName);

        return $this->isValidPageNumber($prevPage) ? (int) $prevPage : null;
    }

    /**
     * Get the cursor for the request.
     *
     * @param  \Illuminate\Pagination\Cursor|string|null  $cursor
     * @param  string  $cursorName
     * @return \Illuminate\Pagination\Cursor|null
     */
    protected function setCursor($cursor, $cursorName)
    {
        $cursor = $cursor ?: static::resolveCurrentCursor($cursorName);
        if (!$cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : $this::resolveCurrentCursor($cursorName, $cursor);
        }
        return $cursor;
    }

    /**
     * Render the paginator using the given view.
     *
     * @param  string|null  $view
     * @param  array  $data
     * @return \Illuminate\Contracts\View\View
     */
    public function links($view = null, $data = [])
    {
        return $this->render($view, $data);
    }

    /**
     * Render the paginator using the given view.
     *
     * @param  string|null  $view
     * @param  array  $data
     * @return \Illuminate\Contracts\View\View
     */
    public function render($view = null, $data = [])
    {
        return static::viewFactory()->make($view ?: static::$defaultView, array_merge($data, [
            'paginator' => $this,
            'elements' => $this->elements(),
        ]));
    }

    /**
     * Get the paginator links as a collection (for JSON responses).
     *
     * @return \Illuminate\Support\Collection
     */
    public function linkCollection()
    {
        return collect($this->elements())->flatMap(function ($item) {
            if (!is_array($item)) {
                return [['url' => null, 'label' => '...', 'active' => false]];
            }

            return collect($item)->map(function ($url, $page) {
                return [
                    'url' => $url,
                    'label' => (string) $page,
                    'active' => $this->currentPage() === $page,
                ];
            });
        })->prepend([
                    'url' => $this->previousPageUrl(),
                    'label' => function_exists('__') ? __('pagination.previous') : 'Previous',
                    'active' => false,
                ])->push([
                    'url' => $this->nextPageUrl(),
                    'label' => function_exists('__') ? __('pagination.next') : 'Next',
                    'active' => false,
                ]);
    }

    /**
     * Get the array of elements to pass to the view.
     *
     * @return array
     */
    protected function elements()
    {
        $window = UrlWindow::make($this);

        return array_filter([
            $window['first'],
            is_array($window['slider']) ? '...' : null,
            $window['slider'],
            is_array($window['last']) ? '...' : null,
            $window['last'],
        ]);
    }

    /**
     * Get the total number of items being paginated.
     *
     * @return int
     */
    public function total()
    {
        return $this->total;
    }

    /**
     * Determine if there are more items in the data source.
     *
     * @return bool
     */
    public function hasMorePages()
    {
        return $this->currentPage() < $this->lastPage();
    }

    /**
     * Get the last page.
     *
     * @return int
     */
    public function lastPage()
    {
        return $this->lastPage;
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'current_page' => $this->currentPage(),
            'data' => $this->items->toArray(),
            'first_page_url' => $this->url(1),
            'from' => $this->firstItem(),
            'last_page' => $this->lastPage(),
            'last_page_url' => $this->url($this->lastPage()),
            'links' => $this->linkCollection()->toArray(),
            'next_page_url' => $this->nextPageUrl(),
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'prev_page_url' => $this->previousPageUrl(),
            'to' => $this->lastItem(),
            'total' => $this->total(),
        ];
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }
}
