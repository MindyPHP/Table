<?php
/**
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Mindy\Orm;

use InvalidArgumentException;
use Mindy\Exception\Exception;
use Mindy\Query\Query;
use Mindy\Exception\InvalidCallException;

/**
 * ActiveQuery represents a DB query associated with an Active Record class.
 *
 * ActiveQuery instances are usually created by [[ActiveRecord::find()]] and [[ActiveRecord::findBySql()]].
 *
 * ActiveQuery mainly provides the following methods to retrieve the query results:
 *
 * - [[one()]]: returns a single record populated with the first row of data.
 * - [[all()]]: returns all records based on the query results.
 * - [[count()]]: returns the number of records.
 * - [[sum()]]: returns the sum over the specified column.
 * - [[average()]]: returns the average over the specified column.
 * - [[min()]]: returns the min over the specified column.
 * - [[max()]]: returns the max over the specified column.
 * - [[scalar()]]: returns the value of the first column in the first row of the query result.
 * - [[column()]]: returns the value of the first column in the query result.
 * - [[exists()]]: returns a value indicating whether the query result has data or not.
 *
 * Because ActiveQuery extends from [[Query]], one can use query methods, such as [[where()]],
 * [[orderBy()]] to customize the query options.
 *
 * ActiveQuery also provides the following additional query options:
 *
 * - [[with()]]: list of relations that this query should be performed with.
 * - [[indexBy()]]: the name of the column by which the query result should be indexed.
 * - [[asArray()]]: whether to return each record as an array.
 *
 * These options can be configured using methods of the same name. For example:
 *
 * ~~~
 * $customers = Customer::find()->with('orders')->asArray()->all();
 * ~~~
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class QuerySet extends Query
{
    /**
     * @var string the name of the ActiveRecord class.
     */
    public $modelClass;
    /**
     * @var array a list of relations that this query should be performed with
     */
    public $with;
    /**
     * @var boolean whether to return each record as an array. If false (default), an object
     * of [[modelClass]] will be created to represent each record.
     */
    public $asArray;

    /**
     * @var string the SQL statement to be executed for retrieving AR records.
     * This is set by [[ActiveRecord::findBySql()]].
     */
    public $sql;


    /**
     * Executes query and returns all results as an array.
     * @param Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return array the query results. If the query results in nothing, an empty array will be returned.
     */
    public function all($db = null)
    {
        $command = $this->createCommand($db);
        $rows = $command->queryAll();
        if (!empty($rows)) {
            $models = $this->createModels($rows);
            if (!empty($this->join) && $this->indexBy === null) {
                $models = $this->removeDuplicatedModels($models);
            }
            if (!empty($this->with)) {
                $this->findWith($this->with, $models);
            }
            return $models;
        } else {
            return [];
        }
    }

    /**
     * Removes duplicated models by checking their primary key values.
     * This method is mainly called when a join query is performed, which may cause duplicated rows being returned.
     * @param array $models the models to be checked
     * @return array the distinctive models
     */
    private function removeDuplicatedModels($models)
    {
        $hash = [];
        /** @var ActiveRecord $class */
        $class = $this->modelClass;
        $pks = $class::primaryKey();

        if (count($pks) > 1) {
            foreach ($models as $i => $model) {
                $key = [];
                foreach ($pks as $pk) {
                    $key[] = $model[$pk];
                }
                $key = serialize($key);
                if (isset($hash[$key])) {
                    unset($models[$i]);
                } else {
                    $hash[$key] = true;
                }
            }
        } else {
            $pk = reset($pks);
            foreach ($models as $i => $model) {
                $key = $model[$pk];
                if (isset($hash[$key])) {
                    unset($models[$i]);
                } else {
                    $hash[$key] = true;
                }
            }
        }

        return array_values($models);
    }

    /**
     * Executes query and returns a single row of result.
     * @param null $db
     * @return null|Orm
     */
    public function get($db = null)
    {
        $command = $this->createCommand($db);
        $row = $command->queryOne();
        if ($row !== false) {
            if ($this->asArray) {
                $model = $row;
            } else {
                /** @var Orm $class */
                $class = $this->modelClass;
                $model = $class::create($row);
            }
            if (!empty($this->with)) {
                $models = [$model];
                $this->findWith($this->with, $models);
                $model = $models[0];
            }
            return $model;
        } else {
            return null;
        }
    }

    /**
     * Creates a DB command that can be used to execute this query.
     * @param \Mindy\Query\Connection $db the DB connection used to create the DB command.
     * If null, the DB connection returned by [[modelClass]] will be used.
     * @return \Mindy\Query\Command the created DB command instance.
     */
    public function createCommand($db = null)
    {
        /** @var Orm $modelClass */
        $modelClass = $this->modelClass;
        if ($db === null) {
            $db = $modelClass::getConnection();
        }

        if ($this->sql === null) {
            $select = $this->select;
            $from = $this->from;

            if ($this->from === null) {
                $tableName = $modelClass::tableName();
                if ($this->select === null && !empty($this->join)) {
                    $this->select = ["$tableName.*"];
                }
                $this->from = [$tableName];
            }
            list ($sql, $params) = $db->getQueryBuilder()->build($this);

            $this->select = $select;
            $this->from = $from;
        } else {
            $sql = $this->sql;
            $params = $this->params;
        }
        return $db->createCommand($sql, $params);
    }

    /**
     * Joins with the specified relations.
     *
     * This method allows you to reuse existing relation definitions to perform JOIN queries.
     * Based on the definition of the specified relation(s), the method will append one or multiple
     * JOIN statements to the current query.
     *
     * If the `$eagerLoading` parameter is true, the method will also eager loading the specified relations,
     * which is equivalent to calling [[with()]] using the specified relations.
     *
     * Note that because a JOIN query will be performed, you are responsible to disambiguate column names.
     *
     * This method differs from [[with()]] in that it will build up and execute a JOIN SQL statement
     * for the primary table. And when `$eagerLoading` is true, it will call [[with()]] in addition with the specified relations.
     *
     * @param array $with the relations to be joined. Each array element represents a single relation.
     * The array keys are relation names, and the array values are the corresponding anonymous functions that
     * can be used to modify the relation queries on-the-fly. If a relation query does not need modification,
     * you may use the relation name as the array value. Sub-relations can also be specified (see [[with()]]).
     * For example,
     *
     * ```php
     * // find all orders that contain books, and eager loading "books"
     * Order::find()->joinWith('books', true, 'INNER JOIN')->all();
     * // find all orders, eager loading "books", and sort the orders and books by the book names.
     * Order::find()->joinWith([
     *     'books' => function ($query) {
     *         $query->orderBy('tbl_item.name');
     *     }
     * ])->all();
     * ```
     *
     * @param boolean|array $eagerLoading whether to eager load the relations specified in `$with`.
     * When this is a boolean, it applies to all relations specified in `$with`. Use an array
     * to explicitly list which relations in `$with` need to be eagerly loaded.
     * @param string|array $joinType the join type of the relations specified in `$with`.
     * When this is a string, it applies to all relations specified in `$with`. Use an array
     * in the format of `relationName => joinType` to specify different join types for different relations.
     * @return static the query object itself
     */
    public function joinWith($with, $eagerLoading = true, $joinType = 'LEFT JOIN')
    {
        $with = (array)$with;
        $this->joinWithRelations(new $this->modelClass, $with, $joinType);

        if (is_array($eagerLoading)) {
            foreach ($with as $name => $callback) {
                if (is_integer($name)) {
                    if (!in_array($callback, $eagerLoading, true)) {
                        unset($with[$name]);
                    }
                } elseif (!in_array($name, $eagerLoading, true)) {
                    unset($with[$name]);
                }
            }
            $this->with($with);
        } elseif ($eagerLoading) {
            $this->with($with);
        }
        return $this;
    }

    /**
     * Inner joins with the specified relations.
     * This is a shortcut method to [[joinWith()]] with the join type set as "INNER JOIN".
     * Please refer to [[joinWith()]] for detailed usage of this method.
     * @param array $with the relations to be joined with
     * @param boolean|array $eagerLoading whether to eager loading the relations
     * @return static the query object itself
     * @see joinWith()
     */
    public function innerJoinWith($with, $eagerLoading = true)
    {
        return $this->joinWith($with, $eagerLoading, 'INNER JOIN');
    }

    /**
     * Modifies the current query by adding join fragments based on the given relations.
     * @param ActiveRecord $model the primary model
     * @param array $with the relations to be joined
     * @param string|array $joinType the join type
     */
    private function joinWithRelations($model, $with, $joinType)
    {
        $relations = [];

        foreach ($with as $name => $callback) {
            if (is_integer($name)) {
                $name = $callback;
                $callback = null;
            }

            $primaryModel = $model;
            $parent = $this;
            $prefix = '';
            while (($pos = strpos($name, '.')) !== false) {
                $childName = substr($name, $pos + 1);
                $name = substr($name, 0, $pos);
                $fullName = $prefix === '' ? $name : "$prefix.$name";
                if (!isset($relations[$fullName])) {
                    $relations[$fullName] = $relation = $primaryModel->getRelation($name);
                    $this->joinWithRelation($parent, $relation, $this->getJoinType($joinType, $fullName));
                } else {
                    $relation = $relations[$fullName];
                }
                $primaryModel = new $relation->modelClass;
                $parent = $relation;
                $prefix = $fullName;
                $name = $childName;
            }

            $fullName = $prefix === '' ? $name : "$prefix.$name";
            if (!isset($relations[$fullName])) {
                $relations[$fullName] = $relation = $primaryModel->getRelation($name);
                if ($callback !== null) {
                    call_user_func($callback, $relation);
                }
                $this->joinWithRelation($parent, $relation, $this->getJoinType($joinType, $fullName));
            }
        }
    }

    /**
     * Returns the join type based on the given join type parameter and the relation name.
     * @param string|array $joinType the given join type(s)
     * @param string $name relation name
     * @return string the real join type
     */
    private function getJoinType($joinType, $name)
    {
        if (is_array($joinType) && isset($joinType[$name])) {
            return $joinType[$name];
        } else {
            return is_string($joinType) ? $joinType : 'INNER JOIN';
        }
    }

    /**
     * Returns the table name used by the specified active query.
     * @param ActiveQuery $query
     * @return string the table name
     */
    private function getQueryTableName($query)
    {
        if (empty($query->from)) {
            /** @var ActiveRecord $modelClass */
            $modelClass = $query->modelClass;
            return $modelClass::tableName();
        } else {
            return reset($query->from);
        }
    }

    /**
     * Joins a parent query with a child query.
     * The current query object will be modified accordingly.
     * @param ActiveQuery $parent
     * @param ActiveRelation $child
     * @param string $joinType
     */
    private function joinWithRelation($parent, $child, $joinType)
    {
        $via = $child->via;
        $child->via = null;
        if ($via instanceof ActiveRelation) {
            // via table
            $this->joinWithRelation($parent, $via, $joinType);
            $this->joinWithRelation($via, $child, $joinType);
            return;
        } elseif (is_array($via)) {
            // via relation
            $this->joinWithRelation($parent, $via[1], $joinType);
            $this->joinWithRelation($via[1], $child, $joinType);
            return;
        }

        $parentTable = $this->getQueryTableName($parent);
        $childTable = $this->getQueryTableName($child);
        if (strpos($parentTable, '{{') === false) {
            $parentTable = '{{' . $parentTable . '}}';
        }
        if (strpos($childTable, '{{') === false) {
            $childTable = '{{' . $childTable . '}}';
        }


        if (!empty($child->link)) {
            $on = [];
            foreach ($child->link as $childColumn => $parentColumn) {
                $on[] = "$parentTable.[[$parentColumn]] = $childTable.[[$childColumn]]";
            }
            $on = implode(' AND ', $on);
        } else {
            $on = '';
        }
        $this->join($joinType, $childTable, $on);


        if (!empty($child->where)) {
            $this->andWhere($child->where);
        }
        if (!empty($child->having)) {
            $this->andHaving($child->having);
        }
        if (!empty($child->orderBy)) {
            $this->addOrderBy($child->orderBy);
        }
        if (!empty($child->groupBy)) {
            $this->addGroupBy($child->groupBy);
        }
        if (!empty($child->params)) {
            $this->addParams($child->params);
        }
        if (!empty($child->join)) {
            foreach ($child->join as $join) {
                $this->join[] = $join;
            }
        }
        if (!empty($child->union)) {
            foreach ($child->union as $union) {
                $this->union[] = $union;
            }
        }
    }

    public function filter(array $q)
    {
        $separator = '__';

        foreach($q as $name => $value) {
            if(is_numeric($name)) {
                throw new InvalidArgumentException('Keys in $q must be a string');
            }

            if(substr_count($name, $separator) > 1) {
                // recursion
                throw new Exception("Not implemented");

            } elseif (strpos($name, $separator) !== false) {
                list($field, $condition) = explode($separator, $name);
                switch($condition) {
                    case 'isnull':
                    case 'exact':
                        $this->andWhere([$field => $q]);
                        break;
                    case 'in':
                        $this->andWhere(['in', $field, $value]);
                        break;
                    case 'gte':
                        $this->andWhere("$field >= :$field", [":$field" => $value]);
                        break;
                    case 'gt':
                        $this->andWhere("$field > :$field", [":$field" => $value]);
                        break;
                    case 'lte':
                        $this->andWhere("$field <= :$field", [":$field" => $value]);
                        break;
                    case 'lt':
                        $this->andWhere("$field < :$field", [":$field" => $value]);
                        break;
                    case 'icontains':
                        // TODO ILIKE
                        // TODO see buildLikeCondition in QueryBuilder. ILIKE? WAT? QueryBuilder dont known about ILIKE
                        break;
                    case 'contains':
                        $this->andWhere(['like', $field, $value]);
                        break;
                    case 'startswith':
                        $this->andWhere(['like', $field, '%' . $value]);
                        break;
                    case 'istartswith':
                        // TODO ILIKE something%
                        // TODO see buildLikeCondition in QueryBuilder. ILIKE? WAT? QueryBuilder dont known about ILIKE
                        break;
                    case 'endswith':
                        $this->andWhere(['like', $field, $value . '%']);
                        break;
                    case 'iendswith':
                        // TODO ILIKE %something
                        // TODO see buildLikeCondition in QueryBuilder. ILIKE? WAT? QueryBuilder dont known about ILIKE
                        break;
                    case 'range':
                        if(count($value) != 2) {
                            throw new InvalidArgumentException('Range condition must be a array of 2 elements. Example: something__range=[1, 2]');
                        }
                        $this->andWhere(array_merge(['between', $field], $value));
                        break;
                    case 'year':
                        // TODO BETWEEN
                        // Entry.objects.filter(pub_date__year=2005)
                        // SELECT ... WHERE pub_date BETWEEN '2005-01-01' AND '2005-12-31';
                        break;
                    case 'month':
                        // TODO
                        // Entry.objects.filter(pub_date__month=12)
                        // SELECT ... WHERE EXTRACT('month' FROM pub_date) = '12';
                        break;
                    case 'day':
                        // TODO
                        break;
                    case 'week_day':
                        // TODO
                        // Entry.objects.filter(pub_date__week_day=2)
                        // No equivalent SQL code fragment is included for this lookup because implementation of
                        // the relevant query varies among different database engines.
                        break;
                    case 'hour':
                        // TODO
                        // Event.objects.filter(timestamp__hour=23)
                        // SELECT ... WHERE EXTRACT('hour' FROM timestamp) = '23';
                        break;
                    case 'minute':
                        // TODO
                        // Event.objects.filter(timestamp__minute=29)
                        // SELECT ... WHERE EXTRACT('minute' FROM timestamp) = '29';
                        break;
                    case 'second':
                        // TODO
                        // Event.objects.filter(timestamp__second=31)
                        // SELECT ... WHERE EXTRACT('second' FROM timestamp) = '31';
                        break;
                    case 'search':
                        // TODO
                        // Entry.objects.filter(headline__search="+Django -jazz Python")
                        // SELECT ... WHERE MATCH(tablename, headline) AGAINST (+Django -jazz Python IN BOOLEAN MODE);
                        break;
                    case 'regex':
                        // TODO
                        // Entry.objects.get(title__regex=r'^(An?|The) +')
                        // SELECT ... WHERE title REGEXP BINARY '^(An?|The) +'; -- MySQL
                        // SELECT ... WHERE REGEXP_LIKE(title, '^(an?|the) +', 'c'); -- Oracle
                        // SELECT ... WHERE title ~ '^(An?|The) +'; -- PostgreSQL
                        // SELECT ... WHERE title REGEXP '^(An?|The) +'; -- SQLite
                        break;
                    case 'iregex':
                        // TODO
                        // SELECT ... WHERE title REGEXP '^(an?|the) +'; -- MySQL
                        // SELECT ... WHERE REGEXP_LIKE(title, '^(an?|the) +', 'i'); -- Oracle
                        // SELECT ... WHERE title ~* '^(an?|the) +'; -- PostgreSQL
                        // SELECT ... WHERE title REGEXP '(?i)^(an?|the) +'; -- SQLite
                        break;
                    default:
                        break;
                }

            } else {
                $this->andWhere([$name => $value]);
            }
        }

        return $this;
    }

    /**
     * Sets the [[asArray]] property.
     * @param boolean $value whether to return the query results in terms of arrays instead of Active Records.
     * @return static the query object itself
     */
    public function asArray($value = true)
    {
        $this->asArray = $value;
        return $this;
    }

    /**
     * Specifies the relations with which this query should be performed.
     *
     * The parameters to this method can be either one or multiple strings, or a single array
     * of relation names and the optional callbacks to customize the relations.
     *
     * A relation name can refer to a relation defined in [[modelClass]]
     * or a sub-relation that stands for a relation of a related record.
     * For example, `orders.address` means the `address` relation defined
     * in the model class corresponding to the `orders` relation.
     *
     * The followings are some usage examples:
     *
     * ~~~
     * // find customers together with their orders and country
     * Customer::find()->with('orders', 'country')->all();
     * // find customers together with their orders and the orders' shipping address
     * Customer::find()->with('orders.address')->all();
     * // find customers together with their country and orders of status 1
     * Customer::find()->with([
     *     'orders' => function($query) {
     *         $query->andWhere('status = 1');
     *     },
     *     'country',
     * ])->all();
     * ~~~
     *
     * @return static the query object itself
     */
    public function with()
    {
        $this->with = func_get_args();
        if (isset($this->with[0]) && is_array($this->with[0])) {
            // the parameter is given as an array
            $this->with = $this->with[0];
        }
        return $this;
    }

    /**
     * Converts found rows into model instances
     * @param array $rows
     * @return array|ActiveRecord[]
     */
    private function createModels($rows)
    {
        $models = [];
        if ($this->asArray) {
            if ($this->indexBy === null) {
                return $rows;
            }
            foreach ($rows as $row) {
                if (is_string($this->indexBy)) {
                    $key = $row[$this->indexBy];
                } else {
                    $key = call_user_func($this->indexBy, $row);
                }
                $models[$key] = $row;
            }
        } else {
            /** @var ActiveRecord $class */
            $class = $this->modelClass;
            if ($this->indexBy === null) {
                foreach ($rows as $row) {
                    $models[] = $class::create($row);
                }
            } else {
                foreach ($rows as $row) {
                    $model = $class::create($row);
                    if (is_string($this->indexBy)) {
                        $key = $model->{$this->indexBy};
                    } else {
                        $key = call_user_func($this->indexBy, $model);
                    }
                    $models[$key] = $model;
                }
            }
        }
        return $models;
    }

    /**
     * Finds records corresponding to one or multiple relations and populates them into the primary models.
     * @param array $with a list of relations that this query should be performed with. Please
     * refer to [[with()]] for details about specifying this parameter.
     * @param array $models the primary models (can be either AR instances or arrays)
     */
    public function findWith($with, &$models)
    {
        $primaryModel = new $this->modelClass;
        $relations = $this->normalizeRelations($primaryModel, $with);
        foreach ($relations as $name => $relation) {
            if ($relation->asArray === null) {
                // inherit asArray from primary query
                $relation->asArray = $this->asArray;
            }
            $relation->populateRelation($name, $models);
        }
    }

    /**
     * @param ActiveRecord $model
     * @param array $with
     * @return ActiveRelationInterface[]
     */
    private function normalizeRelations($model, $with)
    {
        $relations = [];
        foreach ($with as $name => $callback) {
            if (is_integer($name)) {
                $name = $callback;
                $callback = null;
            }
            if (($pos = strpos($name, '.')) !== false) {
                // with sub-relations
                $childName = substr($name, $pos + 1);
                $name = substr($name, 0, $pos);
            } else {
                $childName = null;
            }

            if (!isset($relations[$name])) {
                $relation = $model->getRelation($name);
                $relation->primaryModel = null;
                $relations[$name] = $relation;
            } else {
                $relation = $relations[$name];
            }

            if (isset($childName)) {
                $relation->with[$childName] = $callback;
            } elseif ($callback !== null) {
                call_user_func($callback, $relation);
            }
        }
        return $relations;
    }
}
