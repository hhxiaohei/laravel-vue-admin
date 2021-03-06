<?php


namespace SmallRuralDog\Admin\Grid;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use SmallRuralDog\Admin\Grid;
use SmallRuralDog\Admin\LvaGrid;

/**
 * Class Model
 * @package SmallRuralDog\Admin\Grid
 */
class Model
{
    /**
     * Eloquent model instance of the grid model.
     *
     * @var EloquentModel|Builder
     */
    protected $model;

    /**
     * @var EloquentModel|Builder
     */
    protected $originalModel;

    /**
     * Array of queries of the eloquent model.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $queries;
    /**
     * Sort parameters of the model.
     *
     * @var array
     */
    protected $sort;
    /**
     * @var
     */
    protected $data;
    /**
     * 20 items per page as default.
     * @var int
     */
    protected $perPage = 20;
    /**
     * If the model use pagination.
     *
     * @var bool
     */
    protected $usePaginate = true;

    /**
     * The query string variable used to store the per-page.
     *
     * @var string
     */
    protected $perPageName = 'per_page';

    /**
     * The query string variable used to store the sort.
     *
     * @var string
     */
    protected $sortName = '_sort';

    /**
     * Collection callback.
     *
     * @var \Closure
     */
    protected $collectionCallback;
    /**
     * @var Grid
     */
    protected $grid;

    /**
     * @var Relation
     */
    protected $relation;

    /**
     * @var array
     */
    protected $eagerLoads = [];


    public function __construct(EloquentModel $model, Grid $grid = null)
    {
        $this->model = $model;
        $this->originalModel = $model;
        $this->grid = $grid;
        $this->queries = collect();

    }

    public function eloquent()
    {
        return $this->model;
    }

    public function getModel()
    {
        return $this->model;
    }

    protected function findQueryByMethod($method)
    {
        return $this->queries->first(function ($query) use ($method) {
            return $query['method'] == $method;
        });
    }

    protected function resolvePerPage($paginate)
    {
        if ($perPage = request($this->perPageName)) {
            if (is_array($paginate)) {
                $paginate['arguments'][0] = (int)$perPage;

                return $paginate['arguments'];
            }

            $this->perPage = (int)$perPage;
        }else{
            $this->perPage = $this->grid->getPerPage();
        }

        if (isset($paginate['arguments'][0])) {
            return $paginate['arguments'];
        }


        return [$this->perPage];
    }

    /**
     * 设置每页大小
     */
    protected function setPaginate()
    {
        $paginate = $this->findQueryByMethod('paginate');

        $this->queries = $this->queries->reject(function ($query) {
            return $query['method'] == 'paginate';
        });

        if (!$this->usePaginate) {
            $query = [
                'method' => 'get',
                'arguments' => [],
            ];
        } else {
            $query = [
                'method' => 'paginate',
                'arguments' => $this->resolvePerPage($paginate),
            ];
        }

        $this->queries->push($query);
    }

    /**
     * 设置预加载
     */
    protected function setWith()
    {
        $with = $this->grid->getWiths();

        if ($with) $this->queries->push([
            'method' => 'with',
            'arguments' => $with,
        ]);


    }


    /**
     * 设置排序
     */
    protected function setSort()
    {
        $column = request('sort_prop', null);
        $type = request('sort_order', null);
        if ($column && in_array($column, ['asc', 'desc'])) {
            $this->sort = [
                'column' => $column,
                'type' => $type
            ];
        } else {
            $defaultSort = $this->grid->getDefaultSort();
            $this->sort = [
                'column' => $defaultSort['field'],
                'type' => $defaultSort['order']
            ];
        }

        if (!is_array($this->sort)) {
            return;
        }

        if (empty($this->sort['column']) || empty($this->sort['type'])) {
            return;
        }

        if (Str::contains($this->sort['column'], '.')) {
            //$this->setRelationSort($this->sort['column']);
        } else {
            //$this->resetOrderBy();

            // get column. if contains "cast", set set column as cast
            if (!empty($this->sort['cast'])) {
                $column = "CAST({$this->sort['column']} AS {$this->sort['cast']}) {$this->sort['type']}";
                $method = 'orderByRaw';
                $arguments = [$column];
            } else {
                $column = $this->sort['column'];
                $method = 'orderBy';
                $arguments = [$column, $this->sort['type']];
            }

            $this->queries->push([
                'method' => $method,
                'arguments' => $arguments,
            ]);
        }
    }

    protected function handleInvalidPage(LengthAwarePaginator $paginator)
    {
        if ($paginator->lastPage() && $paginator->currentPage() > $paginator->lastPage()) {
            $lastPageUrl = Request::fullUrlWithQuery([
                $paginator->getPageName() => $paginator->lastPage(),
            ]);
        }
    }

    public function buildData($toArray = false)
    {
        if (empty($this->data)) {


            $collection = $this->get();
        }
        $this->data = $collection;
        return $this->data;
    }

    protected function displayData($data)
    {
        $columcs = $this->grid->getColumns();

        $data = collect($data)->map(function ($row) use ($columcs) {
            collect($columcs)->each(function (Column $column) use ($row) {
                $keys = explode(".", $column->getName());
                $keys = array_filter($keys);
                $keys = array_unique($keys);
                if (count($keys) > 0) {
                    $value = $row[$keys[0]];
                    $row[$keys[0]] = $column->customValueUsing($row, $value);
                }
            });
            return $row;
        })->toArray();


        return $data;
    }

    public function __call($method, $arguments)
    {
        $this->queries->push([
            'method' => $method,
            'arguments' => $arguments,
        ]);

        return $this;
    }


    public function __get($key)
    {
        $data = $this->buildData();

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }


    public function get()
    {

        if ($this->model instanceof LengthAwarePaginator) {
            return $this->model;
        }

        if ($this->relation) {
            $this->model = $this->relation->getQuery();
        }
        $this->setWith();
        $this->setSort();
        $this->setPaginate();

        $this->queries->unique()->each(function ($query) {
            $this->model = call_user_func_array([$this->model, $query['method']], $query['arguments']);
        });

        if ($this->model instanceof Collection) {
            return $this->model;
        }

        if ($this->model instanceof LengthAwarePaginator) {
            return [
                'current_page' => $this->model->currentPage(),
                'per_page' => $this->model->perPage(),
                'last_page' => $this->model->lastPage(),
                'total' => $this->model->total(),
                'data' => $this->displayData($this->model->getCollection())
            ];
        }

    }
}
