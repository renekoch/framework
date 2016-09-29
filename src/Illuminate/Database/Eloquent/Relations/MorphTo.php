<?php

namespace Illuminate\Database\Eloquent\Relations;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class MorphTo extends BelongsTo
{
    /**
     * The type of the polymorphic relation.
     *
     * @var string
     */
    protected $morphType;

    /**
     * The models whose relations are being eager loaded.
     *
     * @var \Illuminate\Database\Eloquent\Collection
     */
    protected $models;

    /**
     * All of the models keyed by ID.
     *
     * @var array
     */
    protected $dictionary = [];

    /**
     * A buffer of dynamic calls to query macros.
     *
     * @var array
     */
    protected $macroBuffer = [];

    /**
     * Create a new morph to relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string[]  $constraintKeys
     * @param  string  $type
     * @param  string  $relation
     * @return void
     */
    public function __construct(Builder $query, Model $parent, $constraintKeys, $type, $relation)
    {
        $this->morphType = $type;

        parent::__construct($query, $parent, $constraintKeys, $relation);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        if (empty($this->constraintKeys)) {
            return;
        }

        return $this->query->first();
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {

        $this->buildDictionary($this->models = Collection::make($models));
    }

    /**
     * Build a dictionary with the models.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    protected function buildDictionary(Collection $models)
    {
        $foreignKeys = array_keys($this->constraintKeys);
        foreach ($models as $model) {
            if ($model->{$this->morphType}) {

                $hash = Arr::buildHash($model, $foreignKeys)[0];
                $this->dictionary[$model->{$this->morphType}][$hash][] = $model;
            }
        }
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
        return $models;
    }

    /**
     * Associate the model instance to the given parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function associate($model)
    {
        foreach($this->constraintKeys as $foreignKey => $otherKey){
            $this->parent->setAttribute($foreignKey, $model->getAttribute($otherKey));
        }

        $this->parent->setAttribute($this->morphType, $model->getMorphClass());

        return $this->parent->setRelation($this->relation, $model);
    }

    /**
     * Dissociate previously associated model from the given parent.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function dissociate()
    {
        foreach($this->constraintKeys as $foreignKey => $otherKey){
            $this->parent->setAttribute($foreignKey, null);
        }
        $this->parent->setAttribute($this->morphType, null);

        return $this->parent->setRelation($this->relation, null);
    }

    /**
     * Get the results of the relationship.
     *
     * Called via eager load method of Eloquent query builder.
     *
     * @return mixed
     */
    public function getEager()
    {
        foreach (array_keys($this->dictionary) as $type) {
            $this->matchToMorphParents($type, $this->getResultsByType($type));
        }

        return $this->models;
    }

    /**
     * Match the results for a given type to their parents.
     *
     * @param  string  $type
     * @param  \Illuminate\Database\Eloquent\Collection|Model[]  $results
     * @return void
     */
    protected function matchToMorphParents($type, Collection $results)
    {
        $foreignKeys = array_values($this->constraintKeys);
        foreach ($results as $result) {
            $hash = $result->getHashKey($foreignKeys)[0];
            if (isset($this->dictionary[$type][$hash])) {
                foreach ($this->dictionary[$type][$hash] as $model) {
                    /**
                     * @var Model $model
                     */
                    $model->setRelation($this->relation, $result);
                }
            }
        }
    }

    /**
     * Get all of the relation results for a type.
     *
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getResultsByType($type)
    {
        $instance = $this->createModelByType($type);

        $table =$instance->getTable().'.';
        $keys = [];

        foreach($instance->getKeyNames() as $foreignKey){
            $keys[$foreignKey] =  $table.$foreignKey;
        }

        /**
         * @var \Illuminate\Database\Query\Builder|Builder $query
         */
        $query = $this->replayMacros($instance->newQuery())
            ->mergeModelDefinedRelationConstraints($this->getQuery())
            ->with($this->getQuery()->getEagerLoads());

        return $query->whereList($keys, $this->gatherKeysByType($type))->get();
    }

    /**
     * Gather all of the foreign keys for a given type.
     *
     * @param  string  $type
     * @return \Illuminate\Support\Collection
     */
    protected function gatherKeysByType($type)
    {
        $foreignKeys = array_keys($this->constraintKeys);

        return collect($this->dictionary[$type])->map(function ($models) use ($foreignKeys) {

            $model = head($models);
            $result = [];
            foreach($foreignKeys as $foreignKey){
                $result[$foreignKey] = $model->{$foreignKey};
            }

            return $result;
        })->values()->unique();
    }

    /**
     * Create a new model instance by type.
     *
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function createModelByType($type)
    {
        $class = $this->parent->getActualClassNameForMorph($type);

        return new $class;
    }

    /**
     * Get the foreign key "type" name.
     *
     * @return string
     */
    public function getMorphType()
    {
        return $this->morphType;
    }

    /**
     * Get the dictionary used by the relationship.
     *
     * @return array
     */
    public function getDictionary()
    {
        return $this->dictionary;
    }

    /**
     * Replay stored macro calls on the actual related instance.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function replayMacros(Builder $query)
    {
        foreach ($this->macroBuffer as $macro) {
            call_user_func_array([$query, $macro['method']], $macro['parameters']);
        }

        return $query;
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        try {
            return parent::__call($method, $parameters);
        }

        // If we tried to call a method that does not exist on the parent Builder instance,
        // we'll assume that we want to call a query macro (e.g. withTrashed) that only
        // exists on related models. We will just store the call and replay it later.
        catch (BadMethodCallException $e) {
            $this->macroBuffer[] = compact('method', 'parameters');

            return $this;
        }
    }
}
