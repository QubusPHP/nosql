<?php

/**
 * Qubus\NoSql
 *
 * @link       https://github.com/QubusPHP/nosql
 * @copyright  2020 Joshua Parker <josh@joshuaparker.blog>
 * @copyright  2017 Muhammad Syifa
 * @license    https://opensource.org/licenses/mit-license.php MIT License
 *
 * @since      1.0.0
 */

declare(strict_types=1);

namespace Qubus\NoSql;

use Closure;
use Qubus\Exception\Data\TypeException;
use Qubus\NoSql\Exceptions\InvalidJsonException;
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

    public function setCollection(Collection $collection): void
    {
        $this->collection = $collection;
    }

    /**
     * Where filter.
     *
     * @param mixed ...$filter
     * @return self
     */
    public function where($filter): static
    {
        $args = func_get_args();
        array_unshift($args, 'AND');
        call_user_func_array(callback: [$this, 'addWhere'], args: $args);
        return $this;
    }

    /**
     * Or where filter.
     *
     * @param mixed ...$filter
     * @return self
     */
    public function orWhere($filter): static
    {
        $args = func_get_args();
        array_unshift($args, 'OR');
        call_user_func_array([$this, 'addWhere'], $args);
        return $this;
    }

    public function map(Closure $mapper): static
    {
        $this->addMapper($mapper);
        return $this;
    }

    public function select(array $columns): static
    {
        $resolvedColumns = [];
        foreach ($columns as $column) {
            $exp = explode(separator: ':', string: $column);
            $col = $exp[0];
            if (count($exp) > 1) {
                $keyAlias = $exp[1];
            } else {
                $keyAlias = $exp[0];
            }
            $resolvedColumns[$col] = $keyAlias;
        }

        $keyAliases = array_values(array: $resolvedColumns);

        return $this->map(mapper: function ($row) use ($resolvedColumns, $keyAliases) {
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
     * @throws TypeException
     */
    public function withOne(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        string $thisKey = '_id'
    ): static {
        if (false === $relation instanceof Query && false === $relation instanceof Collection) {
            throw new TypeException(message: 'Relation must be instanceof Query or Collection.', code: 1);
        }
        return $this->map(mapper: function ($row) use ($relation, $as, $otherKey, $operator, $thisKey) {
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
     * @throws TypeException
     */
    public function withMany(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        string $thisKey = '_id'
    ): static {
        if (false !== $relation instanceof Query && false === $relation instanceof Collection) {
            throw new TypeException(message: 'Relation must be instanceof Query or Collection.', code: 1);
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
     * @param string|Closure $key
     * @param string $asc
     * @throws TypeException
     */
    public function sortBy(string|Closure $key, string $asc = 'asc'): static
    {
        $asc = strtolower(string: $asc);
        if (! in_array(needle: $asc, haystack: ['asc', 'desc'])) {
            throw new TypeException(message: "Sorting must be 'asc' or 'desc'.", code: 1);
        }

        if ($key instanceof Closure) {
            $value = $key;
        } else {
            $value = function ($row) use ($key) {
                return $row[$key];
            };
        }

        $this->addSorter(value: function ($row) use ($value) {
            return $value(new ArrayExtra(items: $row));
        }, asc: $asc);
        return $this;
    }

    public function skip(int $offset): static
    {
        $this->getLimiter()->setOffset(offset: $offset);
        return $this;
    }

    public function take(int $limit, int $offset = 0): static
    {
        $this->getLimiter()->setLimit(limit: $limit)->setOffset(offset: $offset);
        return $this;
    }

    /**
     * Fetching a set of records in collection.
     *
     * If you want to retrieve a specific column define the column in the `$select` array.
     *
     * @param array $select
     */
    public function get(array $select = []): mixed
    {
        if (! empty($select)) {
            $this->select(columns: $select);
        }
        return $this->execute(type: self::TYPE_GET);
    }

    /**
     * Fetch (one) record in a collection.
     *
     * If you want to retrieve a specific column(s) define the column in the `$select` array.
     *
     * @param array $select
     */
    public function first(array $select = []): mixed
    {
        $data = $this->take(limit: 1)->get(select: $select);
        return array_shift($data);
    }

    public function update(array $new): mixed
    {
        return $this->execute(type: self::TYPE_UPDATE, arg: $new);
    }

    public function delete(): mixed
    {
        return $this->execute(type: self::TYPE_DELETE);
    }

    public function save(): mixed
    {
        return $this->execute(type: self::TYPE_SAVE);
    }

    public function count(): int
    {
        return count($this->get());
    }

    public function sum($key): mixed
    {
        $sum = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra(items: $data);
            $sum += $data[$key];
        }
        return $sum;
    }

    public function avg($key): mixed
    {
        $sum = 0;
        $count = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra(items: $data);
            $sum += $data[$key];
            $count++;
        }
        return $sum / $count;
    }

    public function lists(string $key, mixed $resultKey = null): array
    {
        $result = [];
        foreach ($this->get() as $i => $data) {
            $data = new ArrayExtra(items: $data);
            $k = $resultKey ? $data[$resultKey] : $i;
            $result[$k] = $data[$key];
        }
        return $result;
    }

    public function pluck(string $key, $resultKey = null): array
    {
        return $this->lists(key: $key, resultKey: $resultKey);
    }

    public function min(string $key): mixed
    {
        return min($this->lists(key: $key));
    }

    public function max($key): mixed
    {
        return max($this->lists(key: $key));
    }

    public function getPipes(): array
    {
        return $this->pipes;
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    protected function execute(string $type, array $arg = [])
    {
        return $this->getCollection()->execute(query: $this, type: $type, arg: $arg);
    }

    /**
     * @throws TypeException
     */
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
                    return in_array(needle: $row[$key], haystack: (array) $value);
                };
                break;
            case 'not in':
                $filter = function ($row) use ($key, $value) {
                    return ! in_array(needle: $row[$key], haystack: (array) $value);
                };
                break;
            case 'match':
                $filter = function ($row) use ($key, $value) {
                    return (bool) preg_match(pattern: $value, subject: $row[$key]);
                };
                break;
            case 'between':
                if (! is_array(value: $value) || count($value) < 2) {
                    throw new TypeException(message: 'Query between need exactly 2 items in array.');
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

    protected function addFilter(Closure $filter, string $type = 'AND'): void
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof FilterPipe) {
            $pipe = new FilterPipe($this);
            $this->addPipe(pipe: $pipe);
        } else {
            $pipe = $lastPipe;
        }

        $newFilter = function ($row) use ($filter) {
            $row = new ArrayExtra(items: $row);
            return $filter($row);
        };

        $pipe->add(filter: $newFilter, type: $type);
    }

    protected function addMapper(Closure $mapper): void
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof MapperPipe) {
            $pipe = new MapperPipe($this);
            $this->addPipe(pipe: $pipe);
        } else {
            $pipe = $lastPipe;
        }

        $keyId = $this->getCollection()->getKeyId();
        $keyOldId = $this->getCollection()->getKeyOldId();

        $newMapper = function ($row) use ($mapper, $keyId, $keyOldId) {
            $row = new ArrayExtra(items: $row);
            $result = $mapper($row);

            if (is_array(value: $result)) {
                $new = $result;
            } elseif ($result instanceof ArrayExtra) {
                $new = $result->toArray();
            } else {
                $new = null;
            }

            if (is_array(value: $new) && isset($new[$keyId])) {
                if ($row[$keyId] !== $new[$keyId]) {
                    $new[$keyOldId] = $row[$keyId];
                }
            }

            return $new;
        };

        $pipe->add($newMapper);
    }

    protected function addSorter(Closure $value, string $asc): void
    {
        $pipe = new SorterPipe(value: $value, ascending: $asc);
        $this->addPipe($pipe);
    }

    protected function getLimiter(): LimiterPipe
    {
        $lastPipe = $this->getLastPipe();
        if (false === $lastPipe instanceof LimiterPipe) {
            $limiter = new LimiterPipe();
            $this->addPipe(pipe: $limiter);
        } else {
            $limiter = $lastPipe;
        }

        return $limiter;
    }

    protected function addPipe(Pipe $pipe): void
    {
        $this->pipes[] = $pipe;
    }

    protected function getLastPipe()
    {
        return ! empty($this->pipes) ? $this->pipes[count($this->pipes) - 1] : null;
    }

    /**
     * @throws UndefinedMethodException
     */
    public function __call(mixed $method, mixed $args)
    {
        $macro = $this->collection->getMacro(name: $method);

        if ($macro) {
            return call_user_func_array($macro, array_merge([$this], $args));
        } else {
            throw new UndefinedMethodException(
                message: sprintf(
                    'Undefined method or macro `%s`.',
                    $method
                )
            );
        }
    }
}
