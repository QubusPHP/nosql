<?php

/**
 * Qubus\NoSql
 *
 * @link       https://github.com/QubusPHP/nosql
 * @copyright  2020 Joshua Parker
 * @license    https://opensource.org/licenses/mit-license.php MIT License
 *
 * @since      1.0.0
 */

declare(strict_types=1);

namespace Qubus\NoSql;

use Closure;
use Qubus\Exception\Data\TypeException;
use Qubus\NoSql\Exceptions\UndefinedMethodException;
use Qubus\NoSql\Pipes\FilterPipe;
use Qubus\NoSql\Pipes\LimiterPipe;
use Qubus\NoSql\Pipes\MapperPipe;
use Qubus\NoSql\Pipes\Pipe;
use Qubus\NoSql\Pipes\SorterPipe;

use function array_merge;
use function array_shift;
use function array_unshift;
use function array_values;
use function call_user_func_array;
use function count;
use function explode;
use function func_get_args;
use function in_array;
use function is_array;
use function max;
use function min;
use function preg_match;
use function sprintf;
use function strtolower;

class Query
{
    public const TYPE_GET = 'get';
    public const TYPE_INSERT = 'insert';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DELETE = 'delete';
    public const TYPE_SAVE = 'save';

    protected Collection $collection;

    /** @var array $pipes */
    protected array $pipes = [];

    public function __construct(Collection $collection)
    {
        $this->collection = $collection;
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function setCollection(Collection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Where filter.
     *
     * @param mixed ...$filter
     * @return self
     */
    public function where($filter)
    {
        $args = func_get_args();
        array_unshift($args, 'AND');
        call_user_func_array([$this, 'addWhere'], $args);
        return $this;
    }

    /**
     * Or where filter.
     *
     * @param mixed ...$filter
     * @return self
     */
    public function orWhere($filter)
    {
        $args = func_get_args();
        array_unshift($args, 'OR');
        call_user_func_array([$this, 'addWhere'], $args);
        return $this;
    }

    public function map(Closure $mapper)
    {
        $this->addMapper($mapper);
        return $this;
    }

    public function select(array $columns)
    {
        $resolvedColumns = [];
        foreach ($columns as $column) {
            $exp = explode(':', $column);
            $col = $exp[0];
            if (count($exp) > 1) {
                $keyAlias = $exp[1];
            } else {
                $keyAlias = $exp[0];
            }
            $resolvedColumns[$col] = $keyAlias;
        }

        $keyAliases = array_values($resolvedColumns);

        return $this->map(function ($row) use ($resolvedColumns, $keyAliases) {
            foreach ($resolvedColumns as $col => $keyAlias) {
                if (! isset($row[$keyAlias])) {
                    $row[$keyAlias] = $row[$col];
                }
            }

            foreach ($row->toArray() as $col => $value) {
                if (! in_array($col, $keyAliases)) {
                    unset($row[$col]);
                }
            }

            return $row;
        });
    }

    /**
     * 1:1 relation
     *
     * @param Collection|Query $relation
     * @param string $as
     * @param string $otherKey
     * @param string $operator
     * @param string $thisKey
     */
    public function withOne($relation, $as, $otherKey, $operator = '=', $thisKey = '_id')
    {
        if (false === $relation instanceof Query && false === $relation instanceof Collection) {
            throw new TypeException('Relation must be instanceof Query or Collection.', 1);
        }
        return $this->map(function ($row) use ($relation, $as, $otherKey, $operator, $thisKey) {
            $otherData = $relation->where($otherKey, $operator, $row[$thisKey])->first();
            $row[$as] = $otherData;
            return $row;
        });
    }

    /**
     * 1:n relation
     *
     * @param Collection|Query $relation
     * @param string $as
     * @param string $otherKey
     * @param string $operator
     * @param string $thisKey
     */
    public function withMany($relation, $as, $otherKey, $operator = '=', $thisKey = '_id')
    {
        if (false !== $relation instanceof Query && false === $relation instanceof Collection) {
            throw new TypeException('Relation must be instanceof Query or Collection.', 1);
        }
        return $this->map(function ($row) use ($relation, $as, $otherKey, $operator, $thisKey) {
            $otherData = $relation->where($otherKey, $operator, $row[$thisKey])->get();
            $row[$as] = $otherData;
            return $row;
        });
    }

    /**
     * Sort results.
     *
     * @param Closure|string $key
     * @param string $asc
     */
    public function sortBy($key, $asc = 'asc')
    {
        $asc = strtolower($asc);
        if (! in_array($asc, ['asc', 'desc'])) {
            throw new TypeException("Sorting must be 'asc' or 'desc'.", 1);
        }

        if ($key instanceof Closure) {
            $value = $key;
        } else {
            $value = function ($row) use ($key) {
                return $row[$key];
            };
        }

        $this->addSorter(function ($row) use ($value) {
            return $value(new ArrayExtra($row));
        }, $asc);
        return $this;
    }

    public function skip(int $offset)
    {
        $this->getLimiter()->setOffset($offset);
        return $this;
    }

    public function take(int $limit, int $offset = 0)
    {
        $this->getLimiter()->setLimit($limit)->setOffset($offset);
        return $this;
    }

    /**
     * Fetching a set of records in collection.
     *
     * If you want to retrieve a specific column define the column in the `$select` array.
     *
     * @param array $select
     */
    public function get(array $select = [])
    {
        if (! empty($select)) {
            $this->select($select);
        }
        return $this->execute(self::TYPE_GET);
    }

    /**
     * Fetch (one) record in a collection.
     *
     * If you want to retrieve a specific column(s) define the column in the `$select` array.
     *
     * @param array $select
     */
    public function first(array $select = [])
    {
        $data = $this->take(1)->get($select);
        return array_shift($data);
    }

    public function update(array $new)
    {
        return $this->execute(self::TYPE_UPDATE, $new);
    }

    public function delete()
    {
        return $this->execute(self::TYPE_DELETE);
    }

    public function save()
    {
        return $this->execute(self::TYPE_SAVE);
    }

    public function count()
    {
        return count($this->get());
    }

    public function sum($key)
    {
        $sum = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra($data);
            $sum += $data[$key];
        }
        return $sum;
    }

    public function avg($key)
    {
        $sum = 0;
        $count = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra($data);
            $sum += $data[$key];
            $count++;
        }
        return $sum / $count;
    }

    public function lists($key, $resultKey = null)
    {
        $result = [];
        foreach ($this->get() as $i => $data) {
            $data = new ArrayExtra($data);
            $k = $resultKey ? $data[$resultKey] : $i;
            $result[$k] = $data[$key];
        }
        return $result;
    }

    public function pluck($key, $resultKey = null)
    {
        return $this->lists($key, $resultKey);
    }

    public function min($key)
    {
        return min($this->lists($key));
    }

    public function max($key)
    {
        return max($this->lists($key));
    }

    public function getPipes()
    {
        return $this->pipes;
    }

    protected function execute($type, array $arg = [])
    {
        return $this->getCollection()->execute($this, $type, $arg);
    }

    protected function addWhere($type, $filter)
    {
        if ($filter instanceof Closure) {
            return $this->addFilter($filter, $type);
        }

        $args = func_get_args();
        $key = $args[1];
        if (count($args) > 3) {
            $operator = $args[2];
            $value = $args[3];
        } else {
            $operator = '=';
            $value = $args[2];
        }

        switch ($operator) {
            case '=':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] === $value;
                };
                break;
            case '>':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] > $value;
                };
                break;
            case '>=':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] >= $value;
                };
                break;
            case '<':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] < $value;
                };
                break;
            case '<=':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] <= $value;
                };
                break;
            case 'in':
                $filter = function ($row) use ($key, $value) {
                    return in_array($row[$key], (array) $value);
                };
                break;
            case 'not in':
                $filter = function ($row) use ($key, $value) {
                    return ! in_array($row[$key], (array) $value);
                };
                break;
            case 'match':
                $filter = function ($row) use ($key, $value) {
                    return (bool) preg_match($value, $row[$key]);
                };
                break;
            case 'between':
                if (! is_array($value) || count($value) < 2) {
                    throw new TypeException('Query between need exactly 2 items in array.');
                }
                $filter = function ($row) use ($key, $value) {
                    $v = $row[$key];
                    return $v >= $value[0] && $v <= $value[1];
                };
                break;
        }

        if (! $filter) {
            throw new TypeException(
                sprintf(
                    'Operator `%s` is not available.',
                    $operator
                )
            );
        }

        $this->addFilter($filter, $type);
    }

    protected function addFilter(Closure $filter, $type = 'AND')
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof FilterPipe) {
            $pipe = new FilterPipe($this);
            $this->addPipe($pipe);
        } else {
            $pipe = $lastPipe;
        }

        $newFilter = function ($row) use ($filter) {
            $row = new ArrayExtra($row);
            return $filter($row);
        };

        $pipe->add($newFilter, $type);
    }

    protected function addMapper(Closure $mapper)
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof MapperPipe) {
            $pipe = new MapperPipe($this);
            $this->addPipe($pipe);
        } else {
            $pipe = $lastPipe;
        }

        $keyId = $this->getCollection()->getKeyId();
        $keyOldId = $this->getCollection()->getKeyOldId();

        $newMapper = function ($row) use ($mapper, $keyId, $keyOldId) {
            $row = new ArrayExtra($row);
            $result = $mapper($row);

            if (is_array($result)) {
                $new = $result;
            } elseif ($result instanceof ArrayExtra) {
                $new = $result->toArray();
            } else {
                $new = null;
            }

            if (is_array($new) && isset($new[$keyId])) {
                if ($row[$keyId] !== $new[$keyId]) {
                    $new[$keyOldId] = $row[$keyId];
                }
            }

            return $new;
        };

        $pipe->add($newMapper);
    }

    protected function addSorter(Closure $value, $asc)
    {
        $pipe = new SorterPipe($value, $asc);
        $this->addPipe($pipe);
    }

    protected function getLimiter()
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof LimiterPipe) {
            $limiter = new LimiterPipe();
            $this->addPipe($limiter);
        } else {
            $limiter = $lastPipe;
        }

        return $limiter;
    }

    protected function addPipe(Pipe $pipe)
    {
        $this->pipes[] = $pipe;
    }

    protected function getLastPipe()
    {
        return ! empty($this->pipes) ? $this->pipes[count($this->pipes) - 1] : null;
    }

    public function __call($method, $args)
    {
        $macro = $this->collection->getMacro($method);

        if ($macro) {
            return call_user_func_array($macro, array_merge([$this], $args));
        } else {
            throw new UndefinedMethodException(
                sprintf(
                    'Undefined method or macro `%s`.',
                    $method
                )
            );
        }
    }
}
