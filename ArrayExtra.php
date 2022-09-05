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

use ArrayAccess;
use InvalidArgumentException;

use function array_key_exists;
use function array_shift;
use function count;
use function explode;
use function is_array;

class ArrayExtra implements ArrayAccess
{
    /** @var array $items */
    protected array $items = [];

    /**
     * Constructor
     *
     * @param array $items
     * @return void
     */
    public function __construct(array $items)
    {
        $this->items = $this->getArrayValue(value: $items, message: 'Items must be array or ArrayExtra object');
    }

    /**
     * Check if an item or items exist in an array using "dot" notation.
     *
     * @param array $array
     * @param string|int $key
     */
    public static function arrayHas(array $array, mixed $key): bool
    {
        if (array_key_exists(key: $key, array: $array)) {
            return true;
        }

        foreach (explode(separator: '.', string: $key) as $segment) {
            if (is_array(value: $array) && array_key_exists(key: $segment, array: $array)) {
                $array = $array[$segment];
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Get an item from an array using "dot" notation.
     *
     * @param array $array
     * @param string $key
     */
    public static function arrayGet(array $array, mixed $key = null): mixed
    {
        if (null === $key) {
            return $array;
        }

        if (array_key_exists(key: $key, array: $array)) {
            return $array[$key];
        }

        foreach (explode(separator: '.', string: $key) as $segment) {
            if (is_array(value: $array) && array_key_exists(key: $segment, array: $array)) {
                $array = $array[$segment];
            } else {
                return null;
            }
        }

        return $array;
    }

    /**
     * Set an item on an array or object using dot notation.
     */
    public static function arraySet(mixed &$target, array|string $key, mixed $value, bool $overwrite = true): mixed
    {
        $segments = is_array(value: $key) ? $key : explode(separator: '.', string: $key);

        if (($segment = array_shift($segments)) === '*') {
            if (! is_array(value: $target)) {
                $target = [];
            }

            if ($segments) {
                foreach ($target as &$inner) {
                    static::arraySet($inner, $segments, $value, $overwrite);
                }
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (is_array(value: $target)) {
            if ($segments) {
                if (! array_key_exists(key: $segment, array: $target)) {
                    $target[$segment] = [];
                }

                static::arraySet($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || ! array_key_exists(key: $segment, array: $target)) {
                $target[$segment] = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                static::arraySet($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }

    /**
     * Remove item in array.
     *
     * @param array $array
     * @param string $key
     */
    public static function arrayRemove(array &$array, string $key)
    {
        $keys = explode(separator: '.', string: $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (! isset($array[$key]) || ! is_array(value: $array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        unset($array[array_shift($keys)]);
    }

    /**
     * Merge array.
     *
     * @param array $value
     * @return void
     */
    public function merge(array $value): void
    {
        $array = $this->getArrayValue(value: $value, message: 'Value is not mergeable.');

        foreach ($value as $key => $val) {
            $this->items = static::arraySet($this->items, $key, $val, true);
        }
    }

    /**
     * Returns array value.
     *
     * @param array|ArrayExtra $value
     * @param string $message
     * @return array
     */
    protected function getArrayValue(ArrayExtra|array $value, string $message)
    {
        if (! is_array(value: $value) && false === $value instanceof ArrayExtra) {
            throw new InvalidArgumentException(message: $message);
        }

        return is_array(value: $value) ? $value : $value->toArray();
    }

    /**
     * Array items.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->items = static::arraySet($this->items, $offset, $value, true);
    }

    public function offsetExists(mixed $offset): bool
    {
        return static::arrayHas(array: $this->items, key: $offset);
    }

    public function offsetUnset(mixed $offset): void
    {
        static::arrayRemove($this->items, $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return static::arrayGet(array: $this->items, key: $offset);
    }
}
