<?php

namespace Illuminate\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;

class HasManyThrough extends Relation
{
    /**
     * The distance parent model instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $farParent;

    /**
     * The near key on the relationship.
     *
     * @var string[]
     */
    protected $firstKey;

    /**
     * The far key on the relationship.
     *
     * @var string[]
     */
    protected $secondKey;

    /**
     * The local key on the relationship.
     *
     * @var string[]
     */
    protected $localKey;

    /**
     * Create a new has many through relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $farParent
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string[]  $firstKey
     * @param  string[]  $secondKey
     * @param  string[]  $localKey
     */
    public function __construct(Builder $query, Model $farParent, Model $parent, $firstKey, $secondKey, $localKey)
    {
        $this->localKey = $localKey;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->farParent = $farParent;

        parent::__construct($query, $parent);
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
            $parentTable = $this->parent->getTable();
            foreach($this->localKey as $name => $key){
                $this->query->where($parentTable.'.'.$this->firstKey[$name], '=', $this->farParent[$key]);
            }

        }
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parent
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQuery(Builder $query, Builder $parent, $columns = ['*'])
    {
        /**
         * @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
         */
        $parentTable = $this->parent->getTable().'.';

        $this->setJoin($query);

        $query->select($columns);

        foreach($this->getHasCompareKeys() as $field => $key){
            $query->where($field, '=', new Expression($parentTable . $key));
        }

        return $query;
    }

    /**
     * Set the join clause on the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|null  $query
     * @return void
     */
    protected function setJoin(Builder $query = null)
    {
        /**
         * @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
         */
        $query = $query ?: $this->query;

        $relatedKeys  = $this->secondKey;
        $relatedTable = $this->related->getTable();

        $parentKeys  = $this->getQualifiedParentKeyNames();
        $parentTable = $this->parent->getTable();

        $fn = function (JoinClause $join) use ($parentKeys, $relatedTable, $relatedKeys) {
            foreach ($parentKeys as $id => $parentKey) {
                $join->on($parentKey, '=', $relatedTable.'.'.$relatedKeys[ $id ]);
            }
        };

        $query->join($parentTable, $fn);


        if ($this->parentSoftDeletes()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $query->whereNull($this->parent->getQualifiedDeletedAtColumn());
        }
    }

    /**
     * Determine whether close parent of the relation uses Soft Deletes.
     *
     * @return bool
     */
    public function parentSoftDeletes()
    {
        return in_array(SoftDeletes::class, class_uses_recursive(get_class($this->parent)));
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->query->getQuery()->whereList($this->getThroughKey(), $this->getKeys($models));
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array   $models
     * @param  string  $relation
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
     * @param  array   $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            $key = $model->getKey();

            if (isset($dictionary[$key])) {
                $value = $this->related->newCollection($dictionary[$key]);

                $model->setRelation($relation, $value);
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        $foreign = $this->firstKey;

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $result) {
            $dictionary[$result->{$foreign}][] = $result;
        }

        return $dictionary;
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
     * Execute the query and get the first related model.
     *
     * @param  array   $columns
     * @return mixed
     */
    public function first($columns = ['*'])
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $results = $this->take(1)->get($columns);

        /** @noinspection PhpUndefinedMethodInspection */
        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|static
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function firstOrFail($columns = ['*'])
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->parent));
    }

    /**
     * Find a related model by its primary key.
     *
     * @param  mixed|array $id
     * @param  array       $columns
     *
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|null
     */
    public function find($id, $columns = ['*'])
    {
        // Check for composite keys
        $keys = $this->getRelated()->getQualifiedKeyNames();

        if (count($keys) > 1) {

            if (is_string($id)) {
                $id = $this->getRelated()->fromHash($id);
            }

            //its a multiple dimentional array so most likely findMany
            if (is_array(head($id))) {
                return $this->findMany($id, $columns);
            }

            $this->query->getQuery()->whereList($keys, [$id]);
        }
        else {
            if (is_array($id)) {
                return $this->findMany($id, $columns);
            }

            $this->query->where(head($keys), head((array)$id));
        }

        return $this->first($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param  mixed  $ids
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findMany($ids, $columns = ['*'])
    {

        if (empty($ids)) {
            return $this->getRelated()->newCollection();
        }

        $keys = $this->getRelated()->getQualifiedKeyNames();

        $fn = function($id){
            //convert string to keyset
            return is_string($id) ? $this->getRelated()->fromHash($id) : $id;
        };

        //cleanup keys
        $ids = (array) $ids;
        $ids = array_keys($keys) == array_keys($ids) ? [$ids] : $ids;
        $ids = array_map($fn, $ids);

        $this->getQuery()->getQuery()->whereList($keys, $ids);

        return $this->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        if ($result instanceof Collection) {
            if (count($result) == count($id)) {
                return $result;
            }
        }
        elseif (! is_null($result)) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->parent));
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
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

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }

    /**
     * Set the select clause for the relation query.
     *
     * @param  array  $columns
     * @return array
     */
    protected function getSelectColumns(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        $table = $this->parent->getTable().'.';
        foreach($this->firstKey as $key){
            $columns[] = $table . $key;
        }

        return $columns;
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $this->query->getQuery()->addSelect($this->getSelectColumns($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $this->query->getQuery()->addSelect($this->getSelectColumns($columns));

        return $this->query->simplePaginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string[]
     */
    public function getHasCompareKeys()
    {
        return $this->farParent->getQualifiedKeyNames();
    }

    /**
     * Get the qualified foreign key on the related model.
     *
     * @return string
     */
    public function getForeignKeys()
    {
        return $this->related->getTable().'.'.$this->secondKey;
    }

    /**
     * Get the qualified foreign key on the "through" model.
     *
     * @return string[]
     */
    public function getThroughKey()
    {
        $list = $this->firstKey;
        $table =  $this->parent->getTable().'.';
        foreach($list as $key => $val){
            $list[$key] = $table . $val;
        }

        return $list;
    }
}
