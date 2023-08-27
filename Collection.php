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
use Qubus\Exception\Exception;
use Qubus\Exception\IO\FileSystem\DirectoryNotFoundException;
use Qubus\NoSql\Exceptions\InvalidJsonException;
use Qubus\NoSql\Exceptions\UndefinedMethodException;
use stdClass;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_values;
use function call_user_func_array;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function func_get_args;
use function is_dir;
use function json_decode;
use function json_encode;
use function pathinfo;
use function sprintf;
use function uniqid;

use const JSON_PRETTY_PRINT;
use const LOCK_EX;

class Collection
{
    public const KEY_ID = '_id';
    public const KEY_OLD_ID = '_old';

    public const UPDATING  = 'updating';
    public const UPDATED   = 'updated';
    public const INSERTING = 'inserting';
    public const INSERTED  = 'inserted';
    public const DELETING  = 'deleting';
    public const DELETED   = 'deleted';
    public const CHANGED   = 'changed';

    protected ?string $filepath = null;

    protected $resolver = null;

    /** @var array $events */
    protected array $events = [];

    protected bool $transactionMode = false;

    /** @var array|null $transactionData */
    protected ?array $transactionData = null;

    /** @var array $macros */
    protected array $macros = [];

    /** @var string|int|null $lastInsertId */
    protected string|int|null $lastInsertId;

    /** @var array|bool[]|int[]|string[] */
    private array $options;

    public function __construct(string $filepath, array $options = [])
    {
        $this->options = array_merge([
            'file_extension' => '.json',
            'save_format'    => JSON_PRETTY_PRINT,
            'key_prefix'     => '',
            'more_entropy'   => false,
        ], $options);

        $this->filepath = $filepath . $this->options['file_extension'];
    }

    /**
     * @param string $name Macro name.
     */
    public function macro(string $name, callable $callback): void
    {
        $this->macros[$name] = $callback;
    }

    /**
     * Check if macro exists.
     *
     * @param string $name Macro name.
     */
    public function hasMacro(string $name): bool
    {
        return array_key_exists(key: $name, array: $this->macros);
    }

    /**
     * Return macro.
     */
    public function getMacro(string $name)
    {
        return $this->hasMacro($name) ? $this->macros[$name] : null;
    }

    public function getKeyId(): string
    {
        return static::KEY_ID;
    }

    public function getKeyOldId(): string
    {
        return static::KEY_OLD_ID;
    }

    public function isModeTransaction(): bool
    {
        return true === $this->transactionMode;
    }

    public function begin(): void
    {
        $this->transactionMode = true;
    }

    public function commit(): bool|int
    {
        $this->transactionMode = false;
        return $this->save(data: $this->transactionData);
    }

    public function rollback(): void
    {
        $this->transactionMode = false;
        $this->transactionData = null;
    }

    /**
     * @throws Exception
     */
    public function transaction(callable $callback, mixed $that = null, mixed $default = null): mixed
    {
        if ($that === null) {
            $that = $this;
        }

        if ($this->isModeTransaction()) {
            return $callback($that);
        }

        $result = $default;

        $this->begin();

        try {
            $result = $callback($that);
            $this->commit();
        } catch (Exception $ex) {
            $this->rollback();
            throw new Exception();
        }

        return $result;
    }

    public function truncate(): bool|int
    {
        return $this->persists([]);
    }

    /**
     * @param string $event Event name.
     */
    public function on(string $event, callable $callback): void
    {
        if (! isset($this->events[$event])) {
            $this->events[$event] = [];
        }

        $this->events[$event][] = $callback;
    }

    /**
     * @param string $event Event name.
     */
    protected function trigger(string $event, array &$args): void
    {
        $events = $this->events[$event] ?? [];
        foreach ($events as $callback) {
            call_user_func_array(callback: $callback, args: $args);
        }
    }

    /**
     * @throws InvalidJsonException
     */
    public function loadData(): mixed
    {
        if ($this->isModeTransaction() && ! empty($this->transactionData)) {
            return $this->transactionData;
        }

        if (! file_exists(filename: $this->filepath)) {
            $data = [];
        } else {
            $content = file_get_contents(filename: $this->filepath);
            $data = json_decode(json: $content, associative: true);
            if (null === $data) {
                throw new InvalidJsonException(
                    message: sprintf(
                        'Failed to load data. File `%s` contains invalid JSON format.',
                        $this->filepath
                    )
                );
            }
        }

        return $data;
    }

    public function setResolver(callable $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function getResolver(): mixed
    {
        return $this->resolver;
    }

    public function query(): Query
    {
        return new Query(collection: $this);
    }

    public function where($key): mixed
    {
        return call_user_func_array(callback: [$this->query(), 'where'], args: func_get_args());
    }

    public function filter(Closure $closure): mixed
    {
        return $this->query()->filter($closure);
    }

    public function map(Closure $mapper): Query
    {
        return $this->query()->map(mapper: $mapper);
    }

    /**
     * @throws TypeException
     */
    public function sortBy(string $key, string $asc = 'asc'): Query
    {
        return $this->query()->sortBy(key: $key, asc: $asc);
    }

    public function sort(Closure $value): mixed
    {
        return $this->query()->sort($value);
    }

    public function skip(int $offset): Query
    {
        return $this->query()->skip(offset: $offset);
    }

    public function take(int $limit, int $offset = 0): Query
    {
        return $this->query()->take(limit: $limit, offset: $offset);
    }

    /**
     * @throws InvalidJsonException
     */
    public function all(): array
    {
        return array_values($this->loadData());
    }

    /**
     * @throws InvalidJsonException
     */
    public function find($id)
    {
        $data = $this->loadData();
        return $data[$id] ?? null;
    }

    public function lists($key, $resultKey = null): array
    {
        return $this->query()->lists(key: $key, resultKey: $resultKey);
    }

    public function sum($key): mixed
    {
        return $this->query()->sum(key: $key);
    }

    public function count(): ?int
    {
        return $this->query()->count();
    }

    public function avg($key): float|int
    {
        return $this->query()->avg($key);
    }

    public function min($key): mixed
    {
        return $this->query()->min($key);
    }

    public function max($key): mixed
    {
        return $this->query()->max($key);
    }

    /**
     * @throws TypeException
     * @throws InvalidJsonException
     */
    public function insert(array $data): array|bool|int|null
    {
        return $this->execute(query: $this->query(), type: Query::TYPE_INSERT, arg: $data);
    }

    /**
     * @throws TypeException
     * @throws InvalidJsonException
     */
    public function inserts(array $listData): bool|int
    {
        $this->begin();
        foreach ($listData as $data) {
            $this->insert($data);
        }
        return $this->commit();
    }

    public function update(array $data): array|bool|int|null
    {
        return $this->query()->update($data);
    }

    public function delete(): array|bool|int|null
    {
        return $this->query()->delete();
    }

    /**
     * 1:1 relation.
     *
     * @throws TypeException
     */
    public function withOne(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        ?string $thisKey = null
    ): Query {
        return $this->query()->withOne(
            relation: $relation,
            as: $as,
            otherKey: $otherKey,
            operator: $operator,
            thisKey: $thisKey ?: static::KEY_ID
        );
    }

    /**
     * 1:n relation.
     *
     * @throws TypeException
     */
    public function withMany(
        Collection|Query $relation,
        string $as,
        string $otherKey,
        string $operator = '=',
        ?string $thisKey = null
    ): Query {
        return $this->query()->withMany(
            relation: $relation,
            as: $as,
            otherKey: $otherKey,
            operator: $operator,
            thisKey: $thisKey ?: static::KEY_ID
        );
    }

    public function generateKey(): string
    {
        return uniqid($this->options['key_prefix'], (bool) $this->options['more_entropy']);
    }

    /**
     * @throws TypeException
     * @throws InvalidJsonException
     */
    public function execute(Query $query, string $type, array $arg = []): mixed
    {
        if ($query->getCollection() !== $this) {
            throw new TypeException(message: 'Cannot execute query. Query is for different collection.');
        }

        return match ($type) {
            Query::TYPE_GET => $this->executeGet($query),
            Query::TYPE_SAVE => $this->executeSave($query),
            Query::TYPE_INSERT => $this->executeInsert($query, $arg),
            Query::TYPE_UPDATE => $this->executeUpdate($query, $arg),
            Query::TYPE_DELETE => $this->executeDelete($query),
        };
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executePipes(array $pipes): mixed
    {
        $data = $this->loadData() ?: [];
        foreach ($pipes as $pipe) {
            $data = $pipe->process($data);
        }
        return $data;
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executeInsert(Query $query, array $new = []): ?array
    {
        $data = $this->loadData();
        $key = $new[static::KEY_ID] ?? $this->generateKey();

        $this->lastInsertId = $key;

        $newExtra = new ArrayExtra([]);
        $newExtra->merge(value: $new);

        $args = [$newExtra];
        $this->trigger(static::INSERTING, $args);
        $data[$key] = array_merge([
            static::KEY_ID => $key,
        ], $args[0]->toArray());

        $success = $this->persists(data: $data);

        $args = [$data[$key]];
        $this->trigger(static::INSERTED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $data[$key] : null;
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executeUpdate(Query $query, array $new = []): bool|int|null
    {
        $data = $this->loadData();

        $args = [$query, $new];
        $this->trigger(static::UPDATING, $args);

        $pipes = $query->getPipes();
        $rows = $this->executePipes(pipes: $pipes);
        $count = count($rows);
        if (0 === $count) {
            return true;
        }

        $updatedData = [];
        foreach ($rows as $key => $row) {
            $record = new ArrayExtra($data[$key]);
            $record->merge(value: $new);
            $data[$key] = $record->toArray();

            if (isset($new[static::KEY_ID])) {
                $data[$new[static::KEY_ID]] = $data[$key];
                unset($data[$key]);
                $key = $new[static::KEY_ID];
            }
            $updatedData[$key] = $data[$key];
        }

        $success = $this->persists(data: $data);

        $args = [$updatedData];
        $this->trigger(static::UPDATED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $count : 0;
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executeDelete(Query $query): bool|int|null
    {
        $data = $this->loadData();

        $args = [$query];
        $this->trigger(static::DELETING, $args);

        $pipes = $query->getPipes();
        $rows = $this->executePipes(pipes: $pipes);
        $count = count($rows);
        if (0 === $count) {
            return true;
        }

        foreach ($rows as $key => $row) {
            unset($data[$key]);
        }

        $success = $this->persists(data: $data);

        $args = [$rows];
        $this->trigger(static::DELETED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $count : 0;
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executeGet(Query $query)
    {
        $pipes = $query->getPipes();
        $data = $this->executePipes(pipes: $pipes);
        return array_values(array: $data);
    }

    /**
     * @throws InvalidJsonException
     */
    protected function executeSave(Query $query): ?int
    {
        $data = $this->loadData();
        $pipes = $query->getPipes();
        $processed = $this->executePipes(pipes: $pipes);
        $count = count($processed);

        foreach ($processed as $key => $row) {
            // update ID if there is '_old' key
            if (isset($row[static::KEY_OLD_ID])) {
                unset($data[$row[static::KEY_OLD_ID]]);
            }
            // keep ID if there is no '_id'
            if (! isset($row[static::KEY_ID])) {
                $row[static::KEY_ID] = $key;
            }
            $data[$key] = $row;
        }

        $success = $this->persists(data: $data);

        return $success ? $count : 0;
    }

    public function persists(array $data): bool|int
    {
        if ($this->resolver) {
            $data = array_map(callback: $this->getResolver(), array: $data);
        }

        return $this->save(data: $data);
    }

    protected function save(array $data): bool|int
    {
        if ($this->isModeTransaction()) {
            $this->transactionData = $data;
            return true;
        } else {
            if (empty($data)) {
                $data = new stdClass();
            }

            $json = json_encode(value: $data, flags: $this->options['save_format']);

            $filepath = $this->filepath;
            $pathinfo = pathinfo(path: $filepath);
            $dir = $pathinfo['dirname'];
            if (! is_dir(filename: $dir)) {
                throw new DirectoryNotFoundException(
                    message: sprintf(
                        'Cannot save database. Directory `%s` not found or it is not a directory.',
                        $dir
                    )
                );
            }

            return file_put_contents(filename: $filepath, data: $json, flags: LOCK_EX);
        }
    }

    /**
     * Returns the last insert id from the current document being acted upon.
     *
     * @return int|string|null The last insert id.
     */
    public function lastInsertId(): int|string|null
    {
        return $this->lastInsertId;
    }

    /**
     * @throws UndefinedMethodException
     */
    public function __call(mixed $method, mixed $args)
    {
        $macro = $this->getMacro(name: $method);

        if ($macro) {
            return call_user_func_array($macro, array_merge([$this->query()], $args));
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
