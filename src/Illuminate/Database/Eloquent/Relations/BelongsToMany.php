<?php

namespace Illuminate\Database\Eloquent\Relations;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use \Illuminate\Support\Collection as BaseCollection;

class BelongsToMany extends Relation
{

    /**
     * The intermediate table for the relation.
     *
     * @var string
     */
    protected $table;

    /**
     * The foreign key of the parent model.
     *
     * @var string[]
     */
    protected $foreignKey;

    /**
     * The associated key of the relation.
     *
     * @var string[]
     */
    protected $otherKey;

    /**
     * The "name" of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * The pivot table columns to retrieve.
     *
     * @var array
     */
    protected $pivotColumns = [];

    /**
     * Any pivot table restrictions for where clauses.
     *
     * @var array
     */
    protected $pivotWheres = [];

    /**
     * Any pivot table restrictions for whereIn clauses.
     *
     * @var array
     */
    protected $pivotWhereIns = [];

    /**
     * The custom pivot table column for the created_at timestamp.
     *
     * @var string
     */
    protected $pivotCreatedAt;

    /**
     * The custom pivot table column for the updated_at timestamp.
     *
     * @var string
     */
    protected $pivotUpdatedAt;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  \Illuminate\Database\Eloquent\Model   $parent
     * @param  string                                $table
     * @param  string[]                              $foreignKey
     * @param  string[]                              $otherKey
     * @param  string                                $relationName
     */
    public function __construct(Builder $query, Model $parent, $table, $foreignKey, $otherKey, $relationName = null)
    {
        $this->table        = $table;
        $this->otherKey     = $otherKey;
        $this->foreignKey   = $foreignKey;
        $this->relationName = $relationName;

        parent::__construct($query, $parent);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->get();
    }

    /**
     * Get the relationship for eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Collection|Model[]
     */
    public function getEager()
    {
        return $this->query->get($this->getSelectColumns());
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * @param  string $column
     * @param  string $operator
     * @param  mixed  $value
     * @param  string $boolean
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function wherePivot($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->pivotWheres[] = func_get_args();

        $this->getQuery()->where($this->table.'.'.$column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Set a "where in" clause for a pivot table column.
     *
     * @param  string $column
     * @param  mixed  $values
     * @param  string $boolean
     * @param  bool   $not
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function wherePivotIn($column, $values, $boolean = 'and', $not = false)
    {
        $this->pivotWhereIns[] = func_get_args();

        $this->getQuery()->getQuery()->whereIn($this->table.'.'.$column, $values, $boolean, $not);

        return $this;
    }

    /**
     * Set an "or where" clause for a pivot table column.
     *
     * @param  string $column
     * @param  string $operator
     * @param  mixed  $value
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function orWherePivot($column, $operator = null, $value = null)
    {
        return $this->wherePivot($column, $operator, $value, 'or');
    }

    /**
     * Set an "or where in" clause for a pivot table column.
     *
     * @param  string $column
     * @param  mixed  $values
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function orWherePivotIn($column, $values)
    {
        return $this->wherePivotIn($column, $values, 'or');
    }

    /**
     * Execute the query and get the first result.
     *
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function first($columns = ['*'])
    {
        $this->getQuery()->getQuery()->take(1);
        $results = $this->get($columns);

        return $results->first();
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function firstOrFail($columns = ['*'])
    {
        if (!is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->parent));
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        // First we'll add the proper select columns onto the query so it is run with
        // the proper columns. Then, we will get the results and hydrate out pivot
        // models with the result of those columns as a separate model relation.
        $columns = $this->query->getQuery()->columns ? [] : $columns;

        $select = $this->getSelectColumns($columns);

        /**
         * @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $builder
         */
        $builder = $this->query->applyScopes();

        $models = $builder->addSelect($select)->getModels();

        $this->hydratePivotRelation($models);

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @param  int      $perPage
     * @param  array    $columns
     * @param  string   $pageName
     * @param  int|null $page
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $this->query->getQuery()->addSelect($this->getSelectColumns($columns));

        $paginator = $this->query->paginate($perPage, $columns, $pageName, $page);

        $this->hydratePivotRelation($paginator->items());

        return $paginator;
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int    $perPage
     * @param  array  $columns
     * @param  string $pageName
     *
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page')
    {
        $this->query->getQuery()->addSelect($this->getSelectColumns($columns));

        $paginator = $this->query->simplePaginate($perPage, $columns, $pageName);

        $this->hydratePivotRelation($paginator->items());

        return $paginator;
    }

    /**
     * Chunk the results of the query.
     *
     * @param  int      $count
     * @param  callable $callback
     *
     * @return bool
     */
    public function chunk($count, callable $callback)
    {
        $this->query->getQuery()->addSelect($this->getSelectColumns());

        return $this->query->chunk(
          $count,
          function ($results) use ($callback) {
              /**
               * @var Collection $results
               */
              $this->hydratePivotRelation($results->all());

              return $callback($results);
          }
        );
    }

    /**
     * Hydrate the pivot table relationship on the models.
     *
     * @param  array $models
     *
     * @return void
     */
    protected function hydratePivotRelation(array $models)
    {
        // To hydrate the pivot relationship, we will just gather the pivot attributes
        // and create a new Pivot model, which is basically a dynamic model that we
        // will set the attributes, table, and connections on so it they be used.
        foreach ($models as $model) {
            $pivot = $this->newExistingPivot($this->cleanPivotAttributes($model));

            $model->setRelation('pivot', $pivot);
        }
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     *
     * @return array
     */
    protected function cleanPivotAttributes(Model $model)
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            // To get the pivots attributes we will just take any of the attributes which
            // begin with "pivot_" and add those to this arrays, as well as unsetting
            // them from the parent's models since they exist in a different table.
            if (strpos($key, 'pivot_') === 0) {
                $values[ substr($key, 6) ] = $value;

                unset($model->$key);
            }
        }

        return $values;
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        $this->setJoin();

        if (static::$constraints) {
            $this->setWhere();
        }
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  \Illuminate\Database\Eloquent\Builder $parent
     * @param  array|mixed                           $columns
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQuery(Builder $query, Builder $parent, $columns = ['*'])
    {
        if ($parent->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationQueryForSelfJoin($query, $parent, $columns);
        }

        $this->setJoin($query);

        return parent::getRelationQuery($query, $parent, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  \Illuminate\Database\Eloquent\Builder $parent
     * @param  array|mixed                           $columns
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQueryForSelfJoin(Builder $query, Builder $parent, $columns = ['*'])
    {
        /**
         * @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
         */
        $query->select($columns);

        $query->from($this->related->getTable().' as '.$hash = $this->getRelationCountHash());

        $this->related->setTable($hash);

        $this->setJoin($query);

        return parent::getRelationQuery($query, $parent, $columns);
    }

    /**
     * Get a relationship join table hash.
     *
     * @return string
     */
    public function getRelationCountHash()
    {
        return 'laravel_reserved_'.static::$selfJoinCount++;
    }

    /**
     * Set the select clause for the relation query.
     *
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    protected function getSelectColumns(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        return array_merge($columns, $this->getAliasedPivotColumns());
    }

    /**
     * Get the pivot columns for the relation.
     *
     * @return array
     */
    protected function getAliasedPivotColumns()
    {
        $attributes = array_merge(array_values($this->foreignKey), array_values($this->otherKey), $this->pivotColumns);

        // We need to alias all of the pivot columns with the "pivot_" prefix so we
        // can easily extract them out of the models and put them into the pivot
        // relationships when they are retrieved and hydrated into the models.
        $columns = [];

        foreach ($attributes as $attribute) {
            $columns[] = $this->table.'.'.$attribute.' as pivot_'.$attribute;
        }

        return array_unique($columns);
    }

    /**
     * Determine whether the given column is defined as a pivot column.
     *
     * @param  string $column
     *
     * @return bool
     */
    protected function hasPivotColumn($column)
    {
        return in_array($column, $this->pivotColumns);
    }

    /**
     * Set the join clause for the relation query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|null $query
     *
     * @return $this
     */
    protected function setJoin($query = null)
    {
        /**
         * @var \Illuminate\Database\Eloquent\Builder| \Illuminate\Database\Query\Builder $query
         */
        $query = $query ?: $this->query;

        // We need to join to the intermediate table on the related model's primary
        // key column with the intermediate table's foreign key for the related
        // model instance. Then we can set the "where" for the parent models.
        $baseTable = $this->related->getTable().'.';

        $joins = [];
        foreach ($this->getOtherKeys() as $key => $otherKey) {
            $joins[] = [$baseTable.$key, '=', $otherKey];
        }
        $query->join($this->table, $joins);

        return $this;
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function setWhere()
    {
        foreach ($this->getForeignKeys() as $key => $foreignKey) {
            $this->query->where($foreignKey, '=', $this->parent->getAttribute($key));
        }

        return $this;
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  \Illuminate\Database\Eloquent\Model[] $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->query->getQuery()->whereList($this->getForeignKeys(), $this->getKeys($models));
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  \Illuminate\Database\Eloquent\Model[] $models
     * @param  string                                $relation
     *
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  \Illuminate\Database\Eloquent\Model[]    $models
     * @param  \Illuminate\Database\Eloquent\Collection $results
     * @param  string                                   $relation
     *
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have an array dictionary of child objects we can easily match the
        // children back to their parent using the dictionary and the keys on the
        // the parent models. Then we will return the hydrated models back out.
        foreach ($models as $model) {

            $hash = $model->getHashKey()[ 0 ];
            if (isset($dictionary[ $hash ])) {
                $collection = $this->related->newCollection($dictionary[ $hash ]);

                $model->setRelation($relation, $collection);
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model[] $results
     *
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->foreignKey;

        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];
        foreach ($results as $result) {

            //clean up pivot data into pivot relation
            $pivotData = $this->cleanPivotAttributes($result);
            $result->setRelation('pivot', $this->newExistingPivot($pivotData));

            $hash = '';
            foreach ($foreign as $modelKey => $pivotKey) {
                $hash .= $modelKey.(string)$result->pivot->$pivotKey;
            }

            $dictionary[ $hash ][] = $result;
        }

        return $dictionary;
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * E.g.: Touch all roles associated with this user.
     *
     * @return void
     */
    public function touch()
    {
        $keys = $this->getRelated()->getKeyName();

        $columns = $this->getRelatedFreshUpdate();

        // If we actually have IDs for the relation, we will run the query to update all
        // the related model's timestamps, to make sure these all reflect the changes
        // to the parent models. This will help us keep any caching synced up here.
        $ids = $this->getRelatedIds();

        if (count($ids) > 0) {
            $this->getRelated()->newQuery()->whereList($keys, $ids->all())->update($columns);
        }
    }

    /**
     * Get all of the IDs for the related models.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getRelatedIds()
    {
        $keys = $this->getRelated()->getQualifiedKeyName(true);
        $list = $this->getQuery()->getQuery()->select($keys)->get();

        return new BaseCollection($list);
    }

    /**
     * Save a new model and attach it to the parent model.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  array                               $joining
     * @param  bool                                $touch
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function save(Model $model, array $joining = [], $touch = true)
    {
        $model->save(['touch' => false]);

        $this->attach($model, $joining, $touch);

        return $model;
    }

    /**
     * Save an array of new models and attach them to the parent model.
     *
     * @param  \Illuminate\Support\Collection|array $models
     * @param  array                                $joinings
     *
     * @return array
     */
    public function saveMany($models, array $joinings = [])
    {
        foreach ($models as $key => $model) {
            $this->save($model, (array)Arr::get($joinings, $key), false);
        }

        $this->touchIfTouching();

        return $models;
    }

    /**
     * Find a related model by its primary key.
     *
     * @param  mixed|array $id
     * @param  array       $columns
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model[]|null
     */
    public function find($id, $columns = ['*'])
    {
        //if sequential array its probably findMany call
        if (is_array($id) && !Arr::isAssoc($id)) {
            return $this->findMany($id, $columns);
        }

        return $this->findQuery([$id])->first($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param  array $ids
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model[]
     */
    public function findMany($ids, $columns = ['*'])
    {
        if (empty($ids)) {
            return $this->getRelated()->newCollection();
        }

        return $this->findQuery($ids)->get($columns);
    }

    /**
     * Build query for multiple related models by their primary keys.
     *
     * @param $ids
     *
     * @return $this
     */
    protected function findQuery($ids)
    {
        $keys  = $this->getRelated()->getQualifiedKeyName(true);
        $first = reset($ids);
        if (count($keys) == 1 && !is_array($first)) {
            $this->query->getQuery()->whereIn(reset($keys), $ids);
        } else {
            $this->query->getQuery()->whereList($keys, $ids);
        }

        return $this;
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @param  mixed $id
     * @param  array $columns
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        if (is_array($id) && !Arr::isAssoc($id)) {
            if (count($result) == count(array_unique($id))) {
                return $result;
            }
        } elseif (!is_null($result)) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->parent));
    }

    /**
     * Find a related model by its primary key or return new instance of the related model.
     *
     * @param  mixed $id
     * @param  array $columns
     *
     * @return \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Model
     */
    public function findOrNew($id, $columns = ['*'])
    {
        if (is_null($instance = $this->find($id, $columns))) {
            $instance = $this->getRelated()->newInstance();
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @param  array $attributes
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function firstOrNew(array $attributes)
    {
        if (is_null($instance = $this->getQuery()->where($attributes)->first())) {
            $instance = $this->related->newInstance($attributes);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     *
     * @param  array $attributes
     * @param  array $joining
     * @param  bool  $touch
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function firstOrCreate(array $attributes, array $joining = [], $touch = true)
    {
        if (is_null($instance = $this->getQuery()->where($attributes)->first())) {
            $instance = $this->create($attributes, $joining, $touch);
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @param  array $attributes
     * @param  array $values
     * @param  array $joining
     * @param  bool  $touch
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function updateOrCreate(array $attributes, array $values = [], array $joining = [], $touch = true)
    {
        /**
         * @var \Illuminate\Database\Eloquent\Model $instance
         */
        if (is_null($instance = $this->getQuery()->where($attributes)->first())) {
            return $this->create($values, $joining, $touch);
        }

        $instance->fill($values);

        $instance->save(['touch' => false]);

        return $instance;
    }

    /**
     * Create a new instance of the related model.
     *
     * @param  array $attributes
     * @param  array $joining
     * @param  bool  $touch
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array $attributes, array $joining = [], $touch = true)
    {
        $instance = $this->related->newInstance($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

    /**
     * Create an array of new instances of the related models.
     *
     * @param  array $records
     * @param  array $joinings
     *
     * @return array
     */
    public function createMany(array $records, array $joinings = [])
    {
        $instances = [];

        foreach ($records as $key => $record) {
            $instances[] = $this->create($record, (array)Arr::get($joinings, $key), false);
        }

        $this->touchIfTouching();

        return $instances;
    }

    /**
     * Toggles a model (or models) from the parent.
     *
     * Each existing model is detached, and non existing ones are attached.
     *
     * @param  mixed  $ids
     * @param  bool   $touch
     * @return array
     */
    public function toggle($ids, $touch = true)
    {
        $changes = [
            'attached' => [], 'detached' => [],
        ];

        if ($ids instanceof Model) {
            $ids = $ids->getKey();
        }

        if ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        // First we will execute a query to get all of the current attached IDs for
        // the relationship, which will allow us to determine which of them will
        // be attached and which of them will be detached from the join table.
        $current = $this->newPivotQuery()
                    ->pluck($this->otherKey)->all();

        $records = $this->formatRecordsList((array) $ids);

        // Next, we will determine which IDs should get removed from the join table
        // by checking which of the given ID / records is in the list of current
        // records. We will then remove all those rows from the joining table.
        $detach = array_values(array_intersect(
            $current, array_keys($records)
        ));

        if (count($detach) > 0) {
            $this->detach($detach, false);

            $changes['detached'] = $this->castKeys($detach);
        }

        // Finally, for all of the records that were not detached, we'll attach the
        // records into the intermediate table. Then we'll add those attaches to
        // the change list and be ready to return these results to the caller.
        $attach = array_diff_key($records, array_flip($detach));

        if (count($attach) > 0) {
            $this->attach($attach, [], false);

            $changes['attached'] = array_keys($attach);
        }

        if ($touch && (count($changes['attached']) || count($changes['detached']))) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Sync the intermediate tables with a list of IDs without detaching.
     *
     * @param  \Illuminate\Database\Eloquent\Collection|array  $ids
     * @return array
     */
    public function syncWithoutDetaching($ids)
    {
        return $this->sync($ids, false);
    }

    /**
     * Sync the intermediate tables with a list of IDs or collection of models.
     *
     * @param  \Illuminate\Database\Eloquent\Collection|array|Model[] $ids
     * @param  bool                                                   $detaching
     *
     * @return array
     */
    public function sync($ids, $detaching = true)
    {
        $changes = [
          'attached' => [],
          'detached' => [],
          'updated'  => [],
        ];

        if (is_array($ids) && array_keys($ids) == array_keys($this->otherKey)){

            $ids = [$ids];
        }

        elseif ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        // First we need to attach any of the associated models that are not currently
        // in this joining table. We'll spin through the given IDs, checking to see
        // if they exist in the array of current ones, and if not we will insert.
        $current = $this->getCurrentOtherKeys();

        // hash keyed array
        $records = $this->formatRecordsList($ids);

        // Next, we will take the differences of the currents and given IDs and detach
        // all of the entities that exist in the "current" array but are not in the
        // array of the new IDs given to the method which will complete the sync.
        if ($detaching) {

            $detach = [];
            foreach ($current as $hash => $data) {

                if (isset($records[ $hash ])) {
                    continue;
                }
                $detach[] = $current[ $hash ];
                $changes[ 'detached' ][] = ctype_digit((string)$hash) ? (int)$hash : $current[ $hash ];
            }

            if (count($detach) > 0) {
                $this->detach($detach);
            }
        }

        // Now we are finally ready to attach the new records. Note that we'll disable
        // touching until after the entire operation is complete so we don't fire a
        // ton of touch operations until we are totally done syncing the records.
        $changes = array_merge(
          $changes,
          $this->attachNew($records, $current, false)
        );

        if (count($changes[ 'attached' ]) || count($changes[ 'updated' ])) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * get list of otherkeys
     *
     * @return array
     */
    protected function getCurrentOtherKeys()
    {

        $select = [];
        $keys   = [];
        foreach ($this->otherKey as $id => $column) {
            $keys[]   = $id;
            $select[] = $column.' as '.$id;
        }

        return Arr::buildHashArray($this->newPivotQuery()->select($select)->get(), $keys, true);
    }

    /**
     * Format the sync/toggle list so that it is keyed by ID.
     *
     * @param  array $records
     *
     * @return array
     */
    protected function formatRecordsList(array $records)
    {
        $results   = [];
        $otherKeys = array_keys($this->otherKey);
        foreach ($records as $id => $attributes) {

            list($keys, $attributes) = $this->getAttachId($id, $attributes);

            $hash = Arr::buildHash($keys, $otherKeys)[ 0 ];

            $results[ $hash ] = [$keys, $attributes];
        }

        return $results;
    }

    /**
     * Attach all of the IDs that aren't in the current array.
     *
     * @param  array $records
     * @param  array $current
     * @param  bool  $touch
     *
     * @return array
     */
    protected function attachNew(array $records, array $current, $touch = true)
    {
        $changes = ['attached' => [], 'updated' => []];

        foreach ($records as $hash => $data) {

            /**
             * @var array $keys
             * @var array $attributes
             */
            list($keys, $attributes) = $data;

            // If the ID is not in the list of existing pivot IDs, we will insert a new pivot
            // record, otherwise, we will just update this existing record on this joining
            // table, so that the developers will easily update these records pain free.
            if (!isset($current[ $hash ])) {
                $this->attach($keys, $attributes, $touch);

                $changes[ 'attached' ][] = ctype_xdigit((string)$hash) ? (int)$hash : $keys;
            }

            // Now we'll try to update an existing pivot record with the attributes that were
            // given to the method. If the model is actually updated we will add it to the
            // list of updated pivot records so we return them back out to the consumer.
            elseif (count($attributes) > 0 && $this->updateExistingPivot($keys, $attributes, $touch)) {
                $changes[ 'updated' ][] = ctype_xdigit((string)$hash) ? (int)$hash : $keys;
            }
        }

        return $changes;
    }

    /**
     * Cast the given keys to integers if they are numeric and string otherwise.
     *
     * @param  array  $keys
     * @return array
     */
    protected function castKeys(array $keys)
    {
        return (array) array_map(function ($v) {
            return is_numeric($v) ? (int) $v : (string) $v;
        }, $keys);
    }

    /**
     * Update an existing pivot record on the table.
     *
     * @param  mixed $keys
     * @param  array $attributes
     * @param  bool  $touch
     *
     * @return int
     */
    public function updateExistingPivot($keys, array $attributes, $touch = true)
    {
        if (in_array($this->updatedAt(), $this->pivotColumns)) {
            $attributes = $this->setTimestampsOnAttach($attributes, true);
        }

        $updated = $this->newPivotStatementForKeys($keys)->update($attributes);

        if ($touch) {
            $this->touchIfTouching();
        }

        return $updated;
    }

    /**
     * Attach a model to the parent.
     *
     * @param  mixed $keys
     * @param  array $attributes
     * @param  bool  $touch
     *
     * @return void
     */
    public function attach($keys, array $attributes = [], $touch = true)
    {

        if ($keys instanceof Model) {
            $keys = [$keys->getKey(true)];
        }
        elseif ($keys instanceof Collection) {
            $keys = $keys->modelKeys();
        }
        elseif (is_array($keys)) {
            if(array_keys($keys) == array_keys($this->otherKey)){
                $keys = [$keys];
            }
        }
        else{
            //            $keys = $this->related->getKeyName();
            $otherKeys = array_keys($this->otherKey);
            $keys = [reset($otherKeys) => $keys];
        }

        $query = $this->newPivotStatement();

        $query->insert($this->createAttachRecords((array)$keys, $attributes));

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Create an array of records to insert into the pivot table.
     *
     * @param  array $ids
     * @param  array $attributes
     *
     * @return array
     */
    protected function createAttachRecords(array $ids, array $attributes)
    {
        $records = [];

        $timed = ($this->hasPivotColumn($this->createdAt()) || $this->hasPivotColumn($this->updatedAt()));


        // To create the attachment records, we will simply spin through the IDs given
        // and create a new record to insert for each ID. Each ID may actually be a
        // key in the array, with extra attributes to be placed in other columns.
        foreach ($ids as $key => $value) {
            $records[] = $this->attacher($key, $value, $attributes, $timed);
        }

        return $records;
    }

    /**
     * Create a full attachment record payload.
     *
     * @param  int   $key
     * @param  mixed $value
     * @param  array $attributes
     * @param  bool  $timed
     *
     * @return array
     */
    protected function attacher($key, $value, $attributes, $timed)
    {
        list($keys, $extra) = $this->getAttachId($key, $value, $attributes);

        // To create the attachment records, we will simply spin through the IDs given
        // and create a new record to insert for each ID. Each ID may actually be a
        // key in the array, with extra attributes to be placed in other columns.
        $record = $this->createAttachRecord($keys, $timed);

        return array_merge($record, $extra);
    }

    /**
     * Get the attach record ID and extra attributes.
     *
     * @param  mixed $key
     * @param  mixed $value
     * @param  array $attributes
     *
     * @return array
     */
    protected function getAttachId($key, $value, array $attributes = [])
    {
        $otherKeys = $this->otherKey;

        //backwards compability for none composite keys
        if (!is_array($value)) {
            $value = [$this->getSingleOtherKey() => $value];
        }

        $keys = [];
        foreach ($value as $attr => $val) {
            if (isset($otherKeys[ $attr ])) {
                $keys[ $attr ] = $val;
            } else {
                $attributes[ $attr ] = $val;
            }
        }

        //backwards compability for [$key => $attribute[]]
        if (count($otherKeys) === 1 && count($keys) === 0) {
            $keys = [$this->getSingleOtherKey() => $key];
        }

        return [$keys, $attributes];
    }

    /**
     * Create a new pivot attachment record.
     *
     * @param  array $keys
     * @param  bool  $timed
     *
     * @return array
     */
    protected function createAttachRecord($keys, $timed)
    {
        $record = [];
        foreach ($this->foreignKey as $parenktKey => $column) {
            $record[ $column ] = $this->parent->getAttribute($parenktKey);
        }

        foreach ($this->otherKey as $relatedKey => $column) {
            $record[ $column ] = isset($keys[ $relatedKey ]) ? $keys[ $relatedKey ] : $keys[ $column ];
        }

        // If the record needs to have creation and update timestamps, we will make
        // them by calling the parent model's "freshTimestamp" method which will
        // provide us with a fresh timestamp in this model's preferred format.
        if ($timed) {
            $record = $this->setTimestampsOnAttach($record);
        }

        return $record;
    }

    /**
     * Set the creation and update timestamps on an attach record.
     *
     * @param  array $record
     * @param  bool  $exists
     *
     * @return array
     */
    protected function setTimestampsOnAttach(array $record, $exists = false)
    {
        $fresh = $this->parent->freshTimestamp();

        if (!$exists && $this->hasPivotColumn($this->createdAt())) {
            $record[ $this->createdAt() ] = $fresh;
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $record[ $this->updatedAt() ] = $fresh;
        }

        return $record;
    }

    /**
     * Detach models from the relationship.
     *
     * @param  mixed|array $ids
     * @param  bool      $touch
     *
     * @return int
     */
    public function detach($ids = [], $touch = true)
    {
        if ($ids instanceof Collection) {
            $ids = $ids->all();
        }

        if ($ids instanceof Model) {
            $ids = [$ids->getKey(true)];
        }
        elseif (is_array($ids)) {
            $lookupKeys = array_keys($this->otherKey);
            $firstId = reset($ids);

            //if just one keyset wrap in an array
            if (array_keys($ids) == $lookupKeys) {
                $ids = [$ids];
            }
            //if an array of none keyset we assume it an array of first otherKey
            elseif (!is_array($firstId) ) {
                $firstKey = reset($lookupKeys);
                foreach ($ids as $pos => $id) {
                    $keys = (array)$id;
                    $ids[ $pos ] = [$firstKey => reset($keys)];
                }
            }
        }

        //else if any other none false value wrap in an array
        elseif ($ids) {
            $ids = [reset($this->otherKey) => $ids];
        }
        else {
            $ids = [];
        }

        // If associated IDs were passed to the method we will only delete those
        // associations, otherwise all of the association ties will be broken.
        // We'll return the numbers of affected rows when we do the deletes.
        $query = $this->newPivotQuery();

        if (count($ids) > 0) {
            $keys = array_keys(reset($ids));
            $values = array_values($this->otherKey);
            $query->whereList($values == $keys ? $values: $this->otherKey, $ids);
        }

        // Once we have all of the conditions set on the statement, we are ready
        // to run the delete on the pivot table. Then, if the touch parameter
        // is true, we will go ahead and touch all related models to sync.
        $results = $query->delete();

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * If we're touching the parent model, touch.
     *
     * @return void
     */
    public function touchIfTouching()
    {
        if ($this->touchingParent()) {
            $this->getParent()->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Determine if we should touch the parent on sync.
     *
     * @return bool
     */
    protected function touchingParent()
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Attempt to guess the name of the inverse of the relation.
     *
     * @return string
     */
    protected function guessInverseRelation()
    {
        return Str::camel(Str::plural(class_basename($this->getParent())));
    }

    /**
     * Create a new query builder for the pivot table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newPivotQuery()
    {
        $query = $this->newPivotStatement();

        foreach ($this->pivotWheres as $whereArgs) {
            call_user_func_array([$query, 'where'], $whereArgs);
        }
        foreach ($this->foreignKey as $key => $value) {
            $query->where($value,  $this->parent->getAttribute($key));
        }

        foreach ($this->pivotWhereIns as $whereArgs) {
            call_user_func_array([$query, 'whereIn'], $whereArgs);
        }

        return $query;
    }

    /**
     * Get a new plain query builder for the pivot table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newPivotStatement()
    {
        return $this->query->getQuery()->newQuery()->from($this->table);
    }

    /**
     * Get a new pivot statement for a given "other" ID.
     *
     * @param  array|mixed $keys
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newPivotStatementForKeys($keys)
    {
        $query = $this->newPivotQuery();

        if (!is_array($keys)) {
            $keys = [reset($this->otherKey) => $keys];
        }

        foreach ($this->otherKey as $key => $value) {
            $query->where($this->otherKey[ $value ], $keys[ $key ]);
        }

        return $query;
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  array $attributes
     * @param  bool  $exists
     *
     * @return \Illuminate\Database\Eloquent\Relations\Pivot
     */
    public function newPivot(array $attributes = [], $exists = false)
    {
        $pivot = $this->related->newPivot($this->parent, $attributes, $this->table, $exists);

        return $pivot->setPivotKeys($this->foreignKey, $this->otherKey);
    }

    /**
     * Create a new existing pivot model instance.
     *
     * @param  array $attributes
     *
     * @return \Illuminate\Database\Eloquent\Relations\Pivot
     */
    public function newExistingPivot(array $attributes = [])
    {
        return $this->newPivot($attributes, true);
    }

    /**
     * Set the columns on the pivot table to retrieve.
     *
     * @param  array|mixed $columns
     *
     * @return $this
     */
    public function withPivot($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        $this->pivotColumns = array_merge($this->pivotColumns, $columns);

        return $this;
    }

    /**
     * Specify that the pivot table has creation and update timestamps.
     *
     * @param  mixed $createdAt
     * @param  mixed $updatedAt
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function withTimestamps($createdAt = null, $updatedAt = null)
    {
        $this->pivotCreatedAt = $createdAt;
        $this->pivotUpdatedAt = $updatedAt;

        return $this->withPivot($this->createdAt(), $this->updatedAt());
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function createdAt()
    {
        return $this->pivotCreatedAt ?: $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function updatedAt()
    {
        return $this->pivotUpdatedAt ?: $this->parent->getUpdatedAtColumn();
    }

    /**
     * Get the related model's updated at column name.
     *
     * @return string
     */
    public function getRelatedFreshUpdate()
    {
        return [$this->related->getUpdatedAtColumn() => $this->related->freshTimestampString()];
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string
     */
    public function getHasCompareKeys()
    {
        return $this->getForeignKeys();
    }

    /**
     * Get the fully qualified foreign key for the relation.
     *
     * @return string[]
     */
    public function getForeignKeys()
    {
        $list  = $this->foreignKey;
        $table = $this->table.'.';
        foreach ($list as $keyname => $keyvalue) {

            $list[ $keyname ] = $table.$keyvalue;
        }

        return $list;
    }

    /**
     * Get the fully qualified "other key" for the relation.
     *
     * @return string[]
     */
    public function getOtherKeys()
    {
        $list  = $this->otherKey;
        $table = $this->table.'.';
        foreach ($list as $keyname => $keyvalue) {

            $list[ $keyname ] = $table.$keyvalue;
        }

        return $list;
    }

    /**
     * Get the intermediate table for the relationship.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get the relationship name for the relationship.
     *
     * @return string
     */
    public function getRelationName()
    {
        return $this->relationName;
    }

    /**
     * @param bool $columnName
     *
     * @return string
     */
    protected function getSingleOtherKey($columnName = false){

        foreach($this->otherKey as $key => $column){
           return $columnName ? $column : $key;
        }
        return null;
    }

    /**
     * @param bool $columnName
     *
     * @return string
     */
    protected function getSingleForeignKey($columnName = false){

        foreach($this->otherKey as $key => $column){
            return $columnName ? $column : $key;
        }
        return null;
    }
}
