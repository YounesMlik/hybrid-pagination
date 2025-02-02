<?php

namespace YounesMlik\HybridPagination;


use ArrayAccess;
use Closure;
use Exception;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Support\Traits\Tappable;
use Stringable;
use Traversable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @mixin \Illuminate\Support\Collection<TKey, TValue>
 */
abstract class AbstractHybridPaginator implements Htmlable, Stringable
{
    use ForwardsCalls, Tappable;

    /**
     * All of the items being paginated.
     *
     * @var \Illuminate\Support\Collection<TKey, TValue>
     */
    protected $items;

    /**
     * The number of items to be shown per page.
     *
     * @var int
     */
    protected $perPage;

    /**
     * The current page being "viewed".
     *
     * @var int
     */
    protected $currentPage;

    /**
     * The base path to assign to all URLs.
     *
     * @var string
     */
    protected $path = '/';

    /**
     * The query parameters to add to all URLs.
     *
     * @var array
     */
    protected $query = [];

    /**
     * The URL fragment to add to all URLs.
     *
     * @var string|null
     */
    protected $fragment;

    /**
     * The query string variable used to store the page.
     *
     * @var string
     */
    protected $pageName = 'page';

    /**
     * The query string variable used to store the cursor.
     *
     * @var string
     */
    protected $cursorName = 'cursor';

    /**
     * The current cursor.
     *
     * @var \Illuminate\Pagination\Cursor|null
     */
    protected $cursor;

    /**
     * The query string variable used to store the previous page.
     *
     * @var string
     */
    protected $prevPageName = 'prev';

    /**
     * The paginator parameters for the cursor.
     *
     * @var array
     */
    protected $parameters;

    /**
     * The number of links to display on each side of current page link.
     *
     * @var int
     */
    public $onEachSide = 3;

    /**
     * The paginator options.
     *
     * @var array
     */
    protected $options;

    /**
     * The current path resolver callback.
     *
     * @var \Closure
     */
    protected static $currentPathResolver;

    /**
     * The current page resolver callback.
     *
     * @var \Closure
     */
    protected static $currentPageResolver;

    /**
     * The previous page resolver callback.
     *
     * @var \Closure
     */
    protected static $prevPageResolver;

    /**
     * The current cursor resolver callback.
     *
     * @var \Closure
     */
    protected static $currentCursorResolver;

    /**
     * The query string resolver callback.
     *
     * @var \Closure
     */
    protected static $queryStringResolver;

    /**
     * The view factory resolver callback.
     *
     * @var \Closure
     */
    protected static $viewFactoryResolver;

    /**
     * The default pagination view.
     *
     * @var string
     */
    public static $defaultView = 'pagination::tailwind';

    /**
     * The default "simple" pagination view.
     *
     * @var string
     */
    public static $defaultSimpleView = 'pagination::simple-tailwind';
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */

    /**
     * Determine if the given value is a valid page number.
     *
     * @param  int  $page
     * @return bool
     */
    protected function isValidPageNumber($page)
    {
        return $page >= 1 && filter_var($page, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Get the URL for the previous page.
     *
     * @return string|null
     */
    public function previousPageUrl()
    {
        if ($this->currentPage() > 1) {
            return $this->url($this->currentPage() - 1, $this->previousCursor());
        }
    }

    /**
     * The URL for the next page, or null.
     *
     * @return string|null
     */
    public function nextPageUrl()
    {
        $nextCursor = $this->nextCursor();
        if (is_null($nextCursor)) {
            return null;
        }

        if ($this->hasMorePages()) {
            return $this->url($this->currentPage() + 1, $nextCursor);
        } else {
            return null;
        }
    }

    /**
     * Get the "cursor" that points to the previous set of items.
     *
     * @return \Illuminate\Pagination\Cursor|null
     */
    public function previousCursor()
    {
        if ($this->onFirstPage()) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->first(), false);
    }

    /**
     * Get the "cursor" that points to the next set of items.
     *
     * @return \Illuminate\Pagination\Cursor|null
     */
    public function nextCursor()
    {
        if (
            (is_null($this->cursor) && !$this->hasMorePages()) ||
            (!is_null($this->cursor) && $this->cursor->pointsToNextItems() && !$this->hasMorePages())
        ) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->last(), true);
    }

    /**
     * Get a cursor instance for the given item.
     *
     * @param  \ArrayAccess|\stdClass  $item
     * @param  bool  $isNext
     * @return \Illuminate\Pagination\Cursor
     */
    public function getCursorForItem($item, $isNext = true)
    {
        return new Cursor($this->getParametersForItem($item), $isNext);
    }

    /**
     * Get the cursor parameters for a given object.
     *
     * @param  \ArrayAccess|\stdClass  $item
     * @return array
     *
     * @throws \Exception
     */
    public function getParametersForItem($item)
    {
        return (new Collection($this->parameters))
            ->filter()
            ->flip()
            ->map(function ($_, $parameterName) use ($item) {
                if ($item instanceof JsonResource) {
                    $item = $item->resource;
                }

                if (
                    $item instanceof Model &&
                    !is_null($parameter = $this->getPivotParameterForItem($item, $parameterName))
                ) {
                    return $parameter;
                } elseif ($item instanceof ArrayAccess || is_array($item)) {
                    return $this->ensureParameterIsPrimitive(
                        $item[$parameterName] ?? $item[Str::afterLast($parameterName, '.')]
                    );
                } elseif (is_object($item)) {
                    return $this->ensureParameterIsPrimitive(
                        $item->{$parameterName} ?? $item->{Str::afterLast($parameterName, '.')}
                    );
                }

                throw new Exception('Only arrays and objects are supported when cursor paginating items.');
            })->toArray();
    }

    /**
     * Get the cursor parameter value from a pivot model if applicable.
     *
     * @param  \ArrayAccess|\stdClass|\Illuminate\Database\Eloquent\Concerns\HasRelationships  $item
     * @param  string  $parameterName
     * @return string|null
     */
    protected function getPivotParameterForItem($item, $parameterName)
    {
        $table = Str::beforeLast($parameterName, '.');

        foreach ($item->getRelations() as $relation) {
            if ($relation instanceof Pivot && $relation->getTable() === $table) {
                return $this->ensureParameterIsPrimitive(
                    $relation->getAttribute(Str::afterLast($parameterName, '.'))
                );
            }
        }
    }

    /**
     * Ensure the parameter is a primitive type.
     *
     * This can resolve issues that arise the developer uses a value object for an attribute.
     *
     * @param  mixed  $parameter
     * @return mixed
     */
    protected function ensureParameterIsPrimitive($parameter)
    {
        return is_object($parameter) && method_exists($parameter, '__toString')
            ? (string) $parameter
            : $parameter;
    }

    /**
     * Create a range of pagination URLs.
     *
     * @param  int  $start
     * @param  int  $end
     * @return array
     */
    public function getUrlRange($start, $end)
    {
        return (new Collection(range($start, $end)))->mapWithKeys(function ($page) {
            return [
                $page => $this->url(
                    $page,
                    $page < $this->currentPage() ? $this->previousCursor() : $this->nextCursor()
                )
            ];
        })->all();
    }

    /**
     * Get the URL for a given page number.
     *
     * @param  int  $page
     * @param  \Illuminate\Pagination\Cursor|null  $cursor
     * @return string
     */
    public function url($page, $cursor = null)
    {
        if ($page <= 0) {
            $page = 1;
        }

        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = array_merge(
            [$this->pageName => $page],
            [$this->prevPageName => $this->currentPage()],
            is_null($cursor) ? [] : [$this->cursorName => $cursor->encode()]
        );


        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
            . (str_contains($this->path(), '?') ? '&' : '?')
            . Arr::query($parameters)
            . $this->buildFragment();
    }

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @param  string|null  $fragment
     * @return $this|string|null
     */
    public function fragment($fragment = null)
    {
        if (is_null($fragment)) {
            return $this->fragment;
        }

        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Add a set of query string values to the paginator.
     *
     * @param  array|string|null  $key
     * @param  string|null  $value
     * @return $this
     */
    public function appends($key, $value = null)
    {
        if (is_null($key)) {
            return $this;
        }

        if (is_array($key)) {
            return $this->appendArray($key);
        }

        return $this->addQuery($key, $value);
    }

    /**
     * Add an array of query string values.
     *
     * @param  array  $keys
     * @return $this
     */
    protected function appendArray(array $keys)
    {
        foreach ($keys as $key => $value) {
            $this->addQuery($key, $value);
        }

        return $this;
    }

    /**
     * Add all current query string values to the paginator.
     *
     * @return $this
     */
    public function withQueryString()
    {
        if (isset(static::$queryStringResolver)) {
            return $this->appends(call_user_func(static::$queryStringResolver));
        }

        return $this;
    }

    /**
     * Add a query string value to the paginator.
     *
     * @param  string  $key
     * @param  string  $value
     * @return $this
     */
    protected function addQuery($key, $value)
    {
        if ($key !== $this->pageName) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * Build the full fragment portion of a URL.
     *
     * @return string
     */
    protected function buildFragment()
    {
        return $this->fragment ? '#' . $this->fragment : '';
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array  $relations
     * @return $this
     */
    public function loadMorph($relation, $relations)
    {
        $this->getCollection()->loadMorph($relation, $relations);

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array  $relations
     * @return $this
     */
    public function loadMorphCount($relation, $relations)
    {
        $this->getCollection()->loadMorphCount($relation, $relations);

        return $this;
    }

    /**
     * Get the slice of items being paginated.
     *
     * @return array<TKey, TValue>
     */
    public function items()
    {
        return $this->items->all();
    }

    /**
     * Get the number of the first item in the slice.
     *
     * @return int|null
     */
    public function firstItem()
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    /**
     * Get the number of the last item in the slice.
     *
     * @return int|null
     */
    public function lastItem()
    {
        return count($this->items) > 0 ? $this->firstItem() + $this->count() - 1 : null;
    }

    /**
     * Transform each item in the slice of items using a callback.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function through(callable $callback)
    {
        $this->items->transform($callback);

        return $this;
    }

    /**
     * Get the number of items shown per page.
     *
     * @return int
     */
    public function perPage()
    {
        return $this->perPage;
    }

    /**
     * Determine if there are enough items to split into multiple pages.
     *
     * @return bool
     */
    public function hasPages()
    {
        return $this->currentPage() != 1 || $this->hasMorePages();
    }

    /**
     * Determine if the paginator is on the first page.
     *
     * @return bool
     */
    public function onFirstPage()
    {
        return $this->currentPage() <= 1;
    }

    /**
     * Determine if the paginator is on the last page.
     *
     * @return bool
     */
    public function onLastPage()
    {
        return !$this->hasMorePages();
    }

    /**
     * Get the current page.
     *
     * @return int
     */
    public function currentPage()
    {
        return $this->currentPage;
    }

    /**
     * Get the query string variable used to store the page.
     *
     * @return string
     */
    public function getPageName()
    {
        return $this->pageName;
    }

    /**
     * Set the query string variable used to store the page.
     *
     * @param  string  $name
     * @return $this
     */
    public function setPageName($name)
    {
        $this->pageName = $name;

        return $this;
    }

    /**
     * Get the current cursor being paginated.
     *
     * @return \Illuminate\Pagination\Cursor|null
     */
    public function cursor()
    {
        return $this->cursor;
    }

    /**
     * Get the query string variable used to store the cursor.
     *
     * @return string
     */
    public function getCursorName()
    {
        return $this->cursorName;
    }

    /**
     * Set the query string variable used to store the cursor.
     *
     * @param  string  $name
     * @return $this
     */
    public function setCursorName($name)
    {
        $this->cursorName = $name;

        return $this;
    }

    /**
     * Set the base path to assign to all URLs.
     *
     * @param  string  $path
     * @return $this
     */
    public function withPath($path)
    {
        return $this->setPath($path);
    }

    /**
     * Set the base path to assign to all URLs.
     *
     * @param  string  $path
     * @return $this
     */
    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Set the number of links to display on each side of current page link.
     *
     * @param  int  $count
     * @return $this
     */
    public function onEachSide($count)
    {
        $this->onEachSide = $count;

        return $this;
    }

    /**
     * Get the base path for paginator generated URLs.
     *
     * @return string|null
     */
    public function path()
    {
        return $this->path;
    }

    /**
     * Resolve the current cursor or return the default value.
     *
     * @param  string  $cursorName
     * @return \Illuminate\Pagination\Cursor|null
     */
    public static function resolveCurrentCursor($cursorName = 'cursor', $default = null)
    {
        if (isset(static::$currentCursorResolver)) {
            return call_user_func(static::$currentCursorResolver, $cursorName);
        }

        return $default;
    }

    /**
     * Set the current cursor resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function currentCursorResolver(Closure $resolver)
    {
        static::$currentCursorResolver = $resolver;
    }

    /**
     * Resolve the current request path or return the default value.
     *
     * @param  string  $default
     * @return string
     */
    public static function resolveCurrentPath($default = '/')
    {
        if (isset(static::$currentPathResolver)) {
            return call_user_func(static::$currentPathResolver);
        }

        return $default;
    }

    /**
     * Set the current request path resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function currentPathResolver(Closure $resolver)
    {
        static::$currentPathResolver = $resolver;
    }

    /**
     * Resolve the current page or return the default value.
     *
     * @param  string  $pageName
     * @param  int  $default
     * @return int
     */
    public static function resolveCurrentPage($pageName = 'page', $default = 1)
    {
        if (isset(static::$currentPageResolver)) {
            return (int) call_user_func(static::$currentPageResolver, $pageName);
        }

        return $default;
    }

    /**
     * Set the current page resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function currentPageResolver(Closure $resolver)
    {
        static::$currentPageResolver = $resolver;
    }

    /**
     * Resolve the previous page or return the default value.
     *
     * @param  string  $prevPageName
     * @param  int  $default
     * @return int
     */
    public static function resolvePrevPage($prevPageName = 'prev', $default = null)
    {
        if (isset(static::$prevPageResolver)) {
            return (int) call_user_func(static::$prevPageResolver, $prevPageName);
        }

        return $default;
    }

    /**
     * Set the previous page resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function prevPageResolver(Closure $resolver)
    {
        static::$prevPageResolver = $resolver;
    }

    /**
     * Resolve the query string or return the default value.
     *
     * @param  string|array|null  $default
     * @return string
     */
    public static function resolveQueryString($default = null)
    {
        if (isset(static::$queryStringResolver)) {
            return (static::$queryStringResolver)();
        }

        return $default;
    }

    /**
     * Set with query string resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function queryStringResolver(Closure $resolver)
    {
        static::$queryStringResolver = $resolver;
    }

    /**
     * Get an instance of the view factory from the resolver.
     *
     * @return \Illuminate\Contracts\View\Factory
     */
    public static function viewFactory()
    {
        return call_user_func(static::$viewFactoryResolver);
    }

    /**
     * Set the view factory resolver callback.
     *
     * @param  \Closure  $resolver
     * @return void
     */
    public static function viewFactoryResolver(Closure $resolver)
    {
        static::$viewFactoryResolver = $resolver;
    }

    /**
     * Set the default pagination view.
     *
     * @param  string  $view
     * @return void
     */
    public static function defaultView($view)
    {
        static::$defaultView = $view;
    }

    /**
     * Set the default "simple" pagination view.
     *
     * @param  string  $view
     * @return void
     */
    public static function defaultSimpleView($view)
    {
        static::$defaultSimpleView = $view;
    }

    /**
     * Indicate that Tailwind styling should be used for generated links.
     *
     * @return void
     */
    public static function useTailwind()
    {
        static::defaultView('pagination::tailwind');
        static::defaultSimpleView('pagination::simple-tailwind');
    }

    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrap()
    {
        static::useBootstrapFour();
    }

    /**
     * Indicate that Bootstrap 3 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrapThree()
    {
        static::defaultView('pagination::default');
        static::defaultSimpleView('pagination::simple-default');
    }

    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrapFour()
    {
        static::defaultView('pagination::bootstrap-4');
        static::defaultSimpleView('pagination::simple-bootstrap-4');
    }

    /**
     * Indicate that Bootstrap 5 styling should be used for generated links.
     *
     * @return void
     */
    public static function useBootstrapFive()
    {
        static::defaultView('pagination::bootstrap-5');
        static::defaultSimpleView('pagination::simple-bootstrap-5');
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }

    /**
     * Determine if the list of items is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return $this->items->isEmpty();
    }

    /**
     * Determine if the list of items is not empty.
     *
     * @return bool
     */
    public function isNotEmpty()
    {
        return $this->items->isNotEmpty();
    }

    /**
     * Get the number of items for the current page.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Get the paginator's underlying collection.
     *
     * @return \Illuminate\Support\Collection<TKey, TValue>
     */
    public function getCollection()
    {
        return $this->items;
    }

    /**
     * Set the paginator's underlying collection.
     *
     * @param  \Illuminate\Support\Collection<TKey, TValue>  $collection
     * @return $this
     */
    public function setCollection(Collection $collection)
    {
        $this->items = $collection;

        return $this;
    }

    /**
     * Get the paginator options.
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Determine if the given item exists.
     *
     * @param  mixed  $key
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return $this->items->has($key);
    }

    /**
     * Get the item at the given offset.
     *
     * @param  mixed  $key
     * @return mixed
     */
    public function offsetGet($key): mixed
    {
        return $this->items->get($key);
    }

    /**
     * Set the item at the given offset.
     *
     * @param  mixed  $key
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        $this->items->put($key, $value);
    }

    /**
     * Unset the item at the given key.
     *
     * @param  mixed  $key
     * @return void
     */
    public function offsetUnset($key): void
    {
        $this->items->forget($key);
    }

    /**
     * Render the contents of the paginator to HTML.
     *
     * @return string
     */
    public function toHtml()
    {
        return (string) $this->render();
    }

    /**
     * Make dynamic calls into the collection.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->getCollection(), $method, $parameters);
    }

    /**
     * Render the contents of the paginator when casting to a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->render();
    }
}
