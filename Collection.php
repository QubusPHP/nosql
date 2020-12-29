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

    /** @var mixed $resolver */
    protected $resolver;

    /** @var array $events */
    protected array $events = [];

    protected bool $transactionMode = false;

    /** @var array|null $transactionData */
    protected $transactionData;

    /** @var array $macros */
    protected array $macros = [];

    /** @var string|int $lastInsertId */
    protected $lastInsertId = null;

    /**
     * @param string $filepath
     */
    public function __construct($filepath, array $options = [])
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
    public function macro($name, callable $callback): void
    {
        $this->macros[$name] = $callback;
    }

    /**
     * Check if macro exists.
     *
     * @param string $name Macro name.
     */
    public function hasMacro($name): bool
    {
        return array_key_exists($name, $this->macros);
    }

    /**
     * Return macro.
     *
     * @param string $name
     */
    public function getMacro($name)
    {
        return $this->hasMacro($name) ? $this->macros[$name] : null;
    }

    public function getKeyId()
    {
        return static::KEY_ID;
    }

    public function getKeyOldId()
    {
        return static::KEY_OLD_ID;
    }

    public function isModeTransaction(): bool
    {
        return true === $this->transactionMode;
    }

    public function begin()
    {
        $this->transactionMode = true;
    }

    public function commit()
    {
        $this->transactionMode = false;
        return $this->save($this->transactionData);
    }

    public function rollback()
    {
        $this->transactionMode = false;
        $this->transactionData = null;
    }

    public function transaction(callable $callback, $that = null, $default = null)
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

    public function truncate()
    {
        return $this->persists([]);
    }

    /**
     * @param string $event Event name.
     */
    public function on($event, callable $callback): void
    {
        if (! isset($this->events[$event])) {
            $this->events[$event] = [];
        }

        $this->events[$event][] = $callback;
    }

    /**
     * @param sring $event Event name.
     */
    protected function trigger($event, array &$args): void
    {
        $events = $this->events[$event] ?? [];
        foreach ($events as $callback) {
            call_user_func_array($callback, $args);
        }
    }

    public function loadData()
    {
        if ($this->isModeTransaction() && ! empty($this->transactionData)) {
            return $this->transactionData;
        }

        if (! file_exists($this->filepath)) {
            $data = [];
        } else {
            $content = file_get_contents($this->filepath);
            $data = json_decode($content, true);
            if (null === $data) {
                throw new InvalidJsonException(
                    sprintf(
                        'Failed to load data. File `%s` contains invalid JSON format.',
                        $this->filepath
                    )
                );
            }
        }

        return $data;
    }

    public function setResolver(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    public function getResolver()
    {
        return $this->resolver;
    }

    public function query()
    {
        return new Query($this);
    }

    public function where($key)
    {
        return call_user_func_array([$this->query(), 'where'], func_get_args());
    }

    public function filter(Closure $closure)
    {
        return $this->query()->filter($closure);
    }

    public function map(Closure $mapper)
    {
        return $this->query()->map($mapper);
    }

    /**
     * @param string $key
     */
    public function sortBy($key, $asc = 'asc')
    {
        return $this->query()->sortBy($key, $asc);
    }

    public function sort(Closure $value)
    {
        return $this->query()->sort($value);
    }

    public function skip(int $offset)
    {
        return $this->query()->skip($offset);
    }

    public function take(int $limit, int $offset = 0)
    {
        return $this->query()->take($limit, $offset);
    }

    public function all()
    {
        return array_values($this->loadData());
    }

    public function find($id)
    {
        $data = $this->loadData();
        return $data[$id] ?? null;
    }

    public function lists($key, $resultKey = null)
    {
        return $this->query()->lists($key, $resultKey);
    }

    public function sum($key)
    {
        return $this->query()->sum($key);
    }

    public function count()
    {
        return $this->query()->count();
    }

    public function avg($key)
    {
        return $this->query()->avg($key);
    }

    public function min($key)
    {
        return $this->query()->min($key);
    }

    public function max($key)
    {
        return $this->query()->max($key);
    }

    public function insert(array $data)
    {
        return $this->execute($this->query(), Query::TYPE_INSERT, $data);
    }

    public function inserts(array $listData)
    {
        $this->begin();
        foreach ($listData as $data) {
            $this->insert($data);
        }
        return $this->commit();
    }

    public function update(array $data)
    {
        return $this->query()->update();
    }

    public function delete()
    {
        return $this->query()->delete();
    }

    /**
     * 1:1 relation.
     *
     * @param Collection|Query $relation
     */
    public function withOne($relation, string $as, string $otherKey, string $operator = '=', ?string $thisKey = null)
    {
        return $this->query()->withOne($relation, $as, $otherKey, $operator, $thisKey ?: static::KEY_ID);
    }

    /**
     * 1:n relation.
     *
     * @param Collection|Query $relation
     */
    public function withMany($relation, string $as, string $otherKey, string $operator = '=', ?string $thisKey = null)
    {
        return $this->query()->withMany($relation, $as, $otherKey, $operator, $thisKey ?: static::KEY_ID);
    }

    public function generateKey()
    {
        return uniqid($this->options['key_prefix'], (bool) $this->options['more_entropy']);
    }

    public function execute(Query $query, $type, array $arg = [])
    {
        if ($query->getCollection() !== $this) {
            throw new TypeException('Cannot execute query. Query is for different collection.');
        }

        switch ($type) {
            case Query::TYPE_GET:
                return $this->executeGet($query);
            case Query::TYPE_SAVE:
                return $this->executeSave($query);
            case Query::TYPE_INSERT:
                return $this->executeInsert($query, $arg);
            case Query::TYPE_UPDATE:
                return $this->executeUpdate($query, $arg);
            case Query::TYPE_DELETE:
                return $this->executeDelete($query);
        }
    }

    protected function executePipes(array $pipes)
    {
        $data = $this->loadData() ?: [];
        foreach ($pipes as $pipe) {
            $data = $pipe->process($data);
        }
        return $data;
    }

    protected function executeInsert(Query $query, array $new = [])
    {
        $data = $this->loadData();
        $key = $new[static::KEY_ID] ?? $this->generateKey();

        $this->lastInsertId = $key;

        $newExtra = new ArrayExtra([]);
        $newExtra->merge($new);

        $args = [$newExtra];
        $this->trigger(static::INSERTING, $args);
        $data[$key] = array_merge([
            static::KEY_ID => $key,
        ], $args[0]->toArray());

        $success = $this->persists($data);

        $args = [$data[$key]];
        $this->trigger(static::INSERTED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $data[$key] : null;
    }

    protected function executeUpdate(Query $query, array $new = [])
    {
        $data = $this->loadData();

        $args = [$query, $new];
        $this->trigger(static::UPDATING, $args);

        $pipes = $query->getPipes();
        $rows = $this->executePipes($pipes);
        $count = count($rows);
        if (0 === $count) {
            return true;
        }

        $updatedData = [];
        foreach ($rows as $key => $row) {
            $record = new ArrayExtra($data[$key]);
            $record->merge($new);
            $data[$key] = $record->toArray();

            if (isset($new[static::KEY_ID])) {
                $data[$new[static::KEY_ID]] = $data[$key];
                unset($data[$key]);
                $key = $new[static::KEY_ID];
            }
            $updatedData[$key] = $data[$key];
        }

        $success = $this->persists($data);

        $args = [$updatedData];
        $this->trigger(static::UPDATED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $count : 0;
    }

    protected function executeDelete(Query $query)
    {
        $data = $this->loadData();

        $args = [$query];
        $this->trigger(static::DELETING, $args);

        $pipes = $query->getPipes();
        $rows = $this->executePipes($pipes);
        $count = count($rows);
        if (0 === $count) {
            return true;
        }

        foreach ($rows as $key => $row) {
            unset($data[$key]);
        }

        $success = $this->persists($data);

        $args = [$rows];
        $this->trigger(static::DELETED, $args);

        $args = [$data];
        $this->trigger(static::CHANGED, $args);

        return $success ? $count : 0;
    }

    protected function executeGet(Query $query)
    {
        $pipes = $query->getPipes();
        $data = $this->executePipes($pipes);
        return array_values($data);
    }

    protected function executeSave(Query $query)
    {
        $data = $this->loadData();
        $pipes = $query->getPipes();
        $processed = $this->executePipes($pipes);
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

        $success = $this->persists($data);

        return $success ? $count : 0;
    }

    public function persists(array $data)
    {
        if ($this->resolver) {
            $data = array_map($this->getResolver(), $data);
        }

        return $this->save($data);
    }

    protected function save(array $data)
    {
        if ($this->isModeTransaction()) {
            $this->transactionData = $data;
            return true;
        } else {
            if (empty($data)) {
                $data = new stdClass();
            }

            $json = json_encode($data, $this->options['save_format']);

            $filepath = $this->filepath;
            $pathinfo = pathinfo($filepath);
            $dir = $pathinfo['dirname'];
            if (! is_dir($dir)) {
                throw new DirectoryNotFoundException(
                    sprintf(
                        'Cannot save database. Directory `%s` not found or it is not a directory.',
                        $dir
                    )
                );
            }

            return file_put_contents($filepath, $json, LOCK_EX);
        }
    }

    /**
     * Returns the last insert id from the current document being acted upon.
     *
     * @return string|int The last insert id.
     */
    public function lastInsertId()
    {
        return $this->lastInsertId;
    }


    public function __call($method, $args)
    {
        $macro = $this->getMacro($method);

        if ($macro) {
            return call_user_func_array($macro, array_merge([$this->query()], $args));
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
