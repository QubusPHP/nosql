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
use Qubus\Exception\IO\FileSystem\DirectoryNotFoundException;
use Qubus\Exception\IO\FileSystem\FileNotReadableException;
use Qubus\Exception\IO\FileSystem\FileNotWritableException;
use Qubus\NoSql\Exceptions\InvalidJsonException;
use Qubus\NoSql\Exceptions\UndefinedMethodException;
use Qubus\ValueObjects\Identity\Ulid;
use JsonException;
use stdClass;
use Throwable;

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

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

class Collection
{
    public const string KEY_ID = '_id';
    public const string KEY_OLD_ID = '_old';

    public const string UPDATING  = 'updating';
    public const string UPDATED   = 'updated';
    public const string INSERTING = 'inserting';
    public const string INSERTED  = 'inserted';
    public const string DELETING  = 'deleting';
    public const string DELETED   = 'deleted';
    public const string CHANGED   = 'changed';

    protected ?string $filepath = null;

    protected mixed $resolver = null;

    /** @var array $events */
    protected array $events = [];

    protected bool $transactionMode = false;

    /** @var array|null $transactionData */
    protected ?array $transactionData = null;

    /** @var array $macros */
    protected array $macros = [];

    /** @var ?string $lastInsertId */
    protected ?string $lastInsertId = null;

    /** @var array|bool[]|int[]|string[] */
    private array $options = [];

    public function __construct(string $filepath, array $options = [])
    {
        $this->options = array_merge([
            'file_extension' => '.json',
            'save_format'    => JSON_PRETTY_PRINT,
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
        if (! $this->isModeTransaction()) {
            $this->transactionData = null;
        }

        $this->transactionMode = true;
    }

    /**
     * @throws InvalidJsonException
     */
    public function commit(): bool|int
    {
        $this->transactionMode = false;

        if ($this->transactionData === null) {
            return true;
        }

        $data = $this->transactionData;
        $this->transactionData = null;

        return $this->save(data: $data);
    }

    public function rollback(): void
    {
        $this->transactionMode = false;
        $this->transactionData = null;
    }

    /**
     * @throws Throwable
     */
    public function transaction(callable $callback, mixed $that = null, mixed $default = null): mixed
    {
        if ($that === null) {
            $that = $this;
        }

        if ($this->isModeTransaction()) {
            return $callback($that);
        }

        $this->begin();

        try {
            $result = $callback($that);
            $this->commit();
        } catch (Throwable $ex) {
            $this->rollback();
            throw $ex;
        }

        return $result;
    }

    /**
     * @throws InvalidJsonException
     */
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
        if ($this->isModeTransaction() && $this->transactionData !== null) {
            return $this->transactionData;
        }

        if (! file_exists(filename: $this->filepath)) {
            $data = [];
        } else {
            $content = file_get_contents(filename: $this->filepath);
            if (false === $content) {
                throw new FileNotReadableException(
                    message: sprintf('Cannot read database file `%s`.', $this->filepath)
                );
            }

            try {
                $data = json_decode(json: $content, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidJsonException(
                    message: sprintf(
                        'Failed to load data. File `%s` contains invalid JSON format.',
                        $this->filepath
                    ),
                    previous: $exception
                );
            }

            if (! is_array($data)) {
                throw new InvalidJsonException(
                    message: sprintf(
                        'Failed to load data. File `%s` must contain a JSON object or array.',
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

    public function filter(Closure $closure): Query
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
    public function sortBy(string|Closure $key, string $asc = 'asc'): Query
    {
        return $this->query()->sortBy(key: $key, asc: $asc);
    }

    public function sort(Closure $value, string $asc = 'asc'): Query
    {
        return $this->query()->sort(value: $value, asc: $asc);
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
        $ownsTransaction = ! $this->isModeTransaction();
        if ($ownsTransaction) {
            $this->begin();
        }

        try {
            foreach ($listData as $data) {
                $this->insert($data);
            }

            return $ownsTransaction ? $this->commit() : true;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $this->rollback();
            }

            throw $exception;
        }
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function update(array $data): array|bool|int|null
    {
        return $this->query()->update($data);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
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
        return Ulid::generateAsString();
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
            default => throw new TypeException(message: sprintf('Query type `%s` is not available.', $type)),
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

        $newExtra = new ArrayExtra([]);
        $newExtra->merge(value: $new);

        $args = [$newExtra];
        $this->trigger(static::INSERTING, $args);
        $record = array_merge([
            static::KEY_ID => $key,
        ], $args[0]->toArray());
        $key = $record[static::KEY_ID];
        $insertId = Ulid::fromNative($key)->toNative();
        $data[$key] = $record;

        $success = $this->persists(data: $data);
        if ($success) {
            $this->lastInsertId = $insertId;
        }

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
                unset($row[static::KEY_OLD_ID]);
            }
            // keep ID if there is no '_id'
            if (! isset($row[static::KEY_ID])) {
                $row[static::KEY_ID] = $key;
            }
            $data[$row[static::KEY_ID]] = $row;
        }

        $success = $this->persists(data: $data);

        return $success ? $count : 0;
    }

    /**
     * @throws InvalidJsonException
     */
    public function persists(array $data): bool|int
    {
        if ($this->resolver) {
            $data = array_map(callback: $this->getResolver(), array: $data);
        }

        return $this->save(data: $data);
    }

    /**
     * @throws InvalidJsonException
     */
    protected function save(array $data): bool|int
    {
        if ($this->isModeTransaction()) {
            $this->transactionData = $data;
            return true;
        } else {
            if (empty($data)) {
                $data = new stdClass();
            }

            try {
                $json = json_encode(
                    value: $data,
                    flags: $this->options['save_format'] | JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new InvalidJsonException(
                    message: sprintf('Failed to encode data for database file `%s`.', $this->filepath),
                    previous: $exception
                );
            }

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

            $result = file_put_contents(filename: $filepath, data: $json, flags: LOCK_EX);
            if (false === $result) {
                throw new FileNotWritableException(
                    message: sprintf('Cannot write database file `%s`.', $filepath)
                );
            }

            return $result;
        }
    }

    /**
     * Returns the last insert id from the current document being acted upon.
     *
     * @return ?string The last insert id.
     */
    public function lastInsertId(): ?string
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
