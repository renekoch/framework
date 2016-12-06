<?php

namespace Illuminate\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Collection;

class BelongsTo extends Relation
{
    /**
     * The associated key on the parent model.
     *
     * @var string[]
     */
    protected $constraintKeys;

    /**
     * The name of the relationship.
     *
     * @var string
     */
    protected $relation;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new belongs to relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @param  string[]  $constraintKeys
     * @param  string  $relation
     */
    public function __construct(Builder $query, Model $parent, $constraintKeys, $relation)
    {
        $this->constraintKeys = $constraintKeys;
        $this->relation = $relation;

        parent::__construct($query, $parent);
    }

    /**
     * Get the results of the relationship.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getResults()
    {
        return $this->query->first();
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.

            foreach($this->getQualifiedOtherKeyNames() as $foreignKey => $otherKey){
                $this->query->where($otherKey, '=', $this->parent->getAttribute($foreignKey));
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
        if ($parent->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationQueryForSelfRelation($query, $parent, $columns);
        }

        $query->getQuery()->select($columns);

        foreach($this->getQualifiedOtherKeyNames() as $foreignKey => $otherKey){
            $otherKey = $this->wrap($otherKey);
            $query->where($foreignKey, '=', new Expression($otherKey));
        }

        return $query;
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parent
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationQueryForSelfRelation(Builder $query, Builder $parent, $columns = ['*'])
    {
        $hash = $this->getRelationCountHash();
        $query->getQuery()->select($columns)->from($query->getModel()->getTable().' as '.$hash);

        $query->getModel()->setTable($hash);

        foreach($this->getQualifiedForeignKeyNames() as $foreignKey => $otherKey){
            $key = $this->wrap($foreignKey);
            $query->where($hash.'.'.$otherKey, '=', new Expression($key));
        }

        return $query;
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
     * Set the constraints for an eager load of the relation.
     *
     * @param  \Illuminate\Database\Eloquent\Model[] $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        // We will then construct the constraint for our eagerly loading query
        // so it returns the proper models from execution.
        $this->query->getQuery()->whereList($this->getQualifiedOtherKeyNames(), $this->getEagerModelKeys($models));
    }

    /**
     * Gather the keys from an array of related models.
     *
     * @param  \Illuminate\Database\Eloquent\Model[] $models
     *
     * @return array
     */
    protected function getEagerModelKeys(array $models)
    {
        $result = [];

        // First we need to gather all of the keys from the parent models so we know what
        // to query for via the eager loading query. We will add them to an array then
        // execute a "where in" statement to gather up all of those related records.
        $keys = array_flip($this->getConstraintKeys());
        foreach ($models as $model) {
            $hash = $model->getHashKey($keys, true);
            if ($hash !== null) {
                $result[ $hash[ 0 ] ] = $hash[ 1 ];
            }
        }

        // If there are no keys that were not null we will just return an array with either
        // null or 0 in (depending on if incrementing keys are in use) so the query wont
        // fail plus returns zero results, which should be what the developer expects.
        if (count($keys) === 0) {
            return [$this->related->getIncrementing() &&
                    $this->related->getKeyType() === 'int' ? 0 : null, ];
        }

        return $result;
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
            $model->setRelation($relation, null);
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
        // First we will get to build a dictionary of the child models by their primary
        // key of the relationship, then we can easily match the children back onto
        // the parents using that dictionary and the primary key of the children.
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary constructed, we can loop through all the parents
        // and match back onto their children using these keys of the dictionary and
        // the primary key of the children to map them onto the correct instances.
        $keys = array_flip($this->getConstraintKeys());
        foreach ($models as $model) {
            $hash = $model->getHashKey($keys)[ 0 ];
            if (isset($dictionary[ $hash ])) {
                $model->setRelation($relation, $dictionary[ $hash ]);
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        $keys = array_values($this->getConstraintKeys());

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $result) {
            /**
             * @var \Illuminate\Database\Eloquent\Model  $result
             */
            $hash = $result->getHashKey($keys)[0];
            $dictionary[ $hash ] = $result;
        }

        return $dictionary;
    }


    
    /**
     * Associate the model instance to the given parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model|int  $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function associate($model)
    {
        foreach($this->constraintKeys as $otherKey => $foreignKey) {
            $otherKey = ($model instanceof Model ? $model->getAttribute($otherKey) : $model);
            $this->parent->setAttribute($foreignKey, $otherKey);
        }

        if ($model instanceof Model) {
            $this->parent->setRelation($this->relation, $model);
        }

        return $this->parent;
    }

    /**
     * Dissociate previously associated model from the given parent.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function dissociate()
    {
        foreach($this->constraintKeys as $otherKey => $foreignKey) {
            $this->parent->setAttribute($foreignKey, null);
        }

        return $this->parent->setRelation($this->relation, null);
    }

    /**
     * Update the parent model on the relationship.
     *
     * @param  array  $attributes
     * @return mixed
     */
    public function update(array $attributes)
    {
        $instance = $this->getResults();

        return $instance->fill($attributes)->save();
    }

    
    
    /**
     * Get the constraint keys of the relationship.
     *
     * @return string[]
     */
    public function getConstraintKeys()
    {
        return $this->constraintKeys;
    }

    /**
     * Get the fully qualified associated key of the relationship.
     *
     * @return string[]
     */
    public function getQualifiedOtherKeyNames()
    {
        $list = [];
        $table = $this->related->getTable().'.';
        foreach($this->getConstraintKeys() as $foreignKey => $otherKey){
            $list[$foreignKey] = $table.$otherKey;
        }

        return $list;
    }

    /**
     * Get the name of the relationship.
     *
     * @return string
     */
    public function getRelation()
    {
        return $this->relation;
    }

    /**
     * Get the fully qualified associated key of the relationship.
     *
     * @return string[]
     */
    public function getQualifiedForeignKeyNames()
    {
        $list = [];
        $table = $this->parent->getTable().'.';
        foreach($this->getConstraintKeys() as $foreignKey => $otherKey){
            $list[$table.$foreignKey] = $otherKey;
        }

        return $list;
    }
}
