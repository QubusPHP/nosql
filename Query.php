<?php

/**
 * Qubus\NoSql
 *
 * @link       https://github.com/QubusPHP/nosql
 * @copyright  2020 Joshua Parker <joshua@joshuaparker.dev>
 * @copyright  2017 Muhammad Syifa
 * @license    https://opensource.org/licenses/mit-license.php MIT License
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
use function trim;

class Query
{
    public const string TYPE_GET = 'get';
    public const string TYPE_INSERT = 'insert';
    public const string TYPE_UPDATE = 'update';
    public const string TYPE_DELETE = 'delete';
    public const string TYPE_SAVE = 'save';

    protected ?Collection $collection = null;

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
    public function where(mixed $filter): static
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
    public function orWhere(mixed $filter): static
    {
        $args = func_get_args();
        array_unshift($args, 'OR');
        call_user_func_array([$this, 'addWhere'], $args);
        return $this;
    }

    public function filter(Closure $filter): static
    {
        $this->addFilter(filter: $filter);
        return $this;
    }

    public function map(Closure $mapper): static
    {
        $this->addMapper($mapper);
        return $this;
    }

    /**
     * @throws TypeException
     */
    public function sort(Closure $value, string $asc = 'asc'): static
    {
        return $this->sortBy(key: $value, asc: $asc);
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
                $row[$keyAlias] = $row[$col];
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
     * @return Query
     * @throws TypeException
     */
    public function withOne(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        string $thisKey = '_id'
    ): static {
        return $this->map(mapper: function ($row) use ($relation, $as, $otherKey, $operator, $thisKey) {
            $query = $relation instanceof Collection ? $relation->query() : clone $relation;
            $otherData = $query->where($otherKey, $operator, $row[$thisKey])->first();
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
     * @return Query
     * @throws TypeException
     */
    public function withMany(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        string $thisKey = '_id'
    ): static {
        return $this->map(function ($row) use ($relation, $as, $otherKey, $operator, $thisKey) {
            $query = $relation instanceof Collection ? $relation->query() : clone $relation;
            $otherData = $query->where($otherKey, $operator, $row[$thisKey])->get();
            $row[$as] = $otherData;
            return $row;
        });
    }

    /**
     * Sort results.
     *
     * @param string|Closure $key
     * @param string $asc
     * @return Query
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
     * @return mixed
     * @throws InvalidJsonException
     * @throws TypeException
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
     * @return mixed
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function first(array $select = []): mixed
    {
        $data = $this->take(limit: 1)->get(select: $select);
        return array_shift($data);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function update(array $new): mixed
    {
        return $this->execute(type: self::TYPE_UPDATE, arg: $new);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function delete(): mixed
    {
        return $this->execute(type: self::TYPE_DELETE);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function save(): mixed
    {
        return $this->execute(type: self::TYPE_SAVE);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function count(): int
    {
        return count($this->get());
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function sum($key): mixed
    {
        $sum = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra(items: $data);
            $sum += $data[$key];
        }
        return $sum;
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function avg($key): int|float
    {
        $sum = 0;
        $count = 0;
        foreach ($this->get() as $data) {
            $data = new ArrayExtra(items: $data);
            $sum += $data[$key];
            $count++;
        }
        return 0 === $count ? 0 : $sum / $count;
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
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

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function pluck(string $key, $resultKey = null): array
    {
        return $this->lists(key: $key, resultKey: $resultKey);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function min(string $key): mixed
    {
        $values = $this->lists(key: $key);
        return [] === $values ? null : min($values);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function max($key): mixed
    {
        $values = $this->lists(key: $key);
        return [] === $values ? null : max($values);
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
    protected function addWhere($type, $filter): void
    {
        if ($filter instanceof Closure) {
            $this->addFilter($filter, $type);

            return;
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

        $operator = strtolower(string: trim(string: (string) $operator));

        switch ($operator) {
            case '=':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] === $value;
                };
                break;
            case '!=':
            case '<>':
                $filter = function ($row) use ($key, $value) {
                    return $row[$key] !== $value;
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
                if (! is_array(value: $value) || 2 !== count($value)) {
                    throw new TypeException(message: 'Query between need exactly 2 items in array.');
                }
                $filter = function ($row) use ($key, $value) {
                    $v = $row[$key];
                    return $v >= $value[0] && $v <= $value[1];
                };
                break;
            default:
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
            $pipe = new FilterPipe();
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
            $pipe = new MapperPipe();
            $this->addPipe(pipe: $pipe);
        } else {
            $pipe = $lastPipe;
        }

        $keyId = $this->getCollection()->getKeyId();
        $keyOldId = $this->getCollection()->getKeyOldId();

        $newMapper = function ($row) use ($mapper, $keyId, $keyOldId) {
            $row = new ArrayExtra(items: $row);
            $originalId = $row[$keyId];
            $result = $mapper($row);

            if (is_array(value: $result)) {
                $new = $result;
            } elseif ($result instanceof ArrayExtra) {
                $new = $result->toArray();
            } else {
                $new = null;
            }

            if (is_array(value: $new) && isset($new[$keyId])) {
                if ($originalId !== $new[$keyId]) {
                    $new[$keyOldId] = $originalId;
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

    public function __clone(): void
    {
        foreach ($this->pipes as $key => $pipe) {
            $this->pipes[$key] = clone $pipe;
        }
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
