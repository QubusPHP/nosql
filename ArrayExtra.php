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
    public function __construct($items)
    {
        $this->items = $this->getArrayValue($items, 'Items must be array or ArrayExtra object');
    }

    /**
     * Check if an item or items exist in an array using "dot" notation.
     *
     * @param array  $array
     * @param string|array  $keys
     * @return bool
     */
    public static function arrayHas(array $array, $key)
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
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
     * @param array  $array
     * @param string  $key
     * @return mixed
     */
    public static function arrayGet(array $array, $key)
    {
        if (null === $key) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return null;
            }
        }

        return $array;
    }

    /**
     * Set an item on an array or object using dot notation.
     *
     * @param mixed $target
     * @param string|array  $key
     * @param mixed $value
     * @return mixed
     */
    public static function arraySet(&$target, $key, $value, bool $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        if (($segment = array_shift($segments)) === '*') {
            if (! is_array($target)) {
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
        } elseif (is_array($target)) {
            if ($segments) {
                if (! array_key_exists($segment, $target)) {
                    $target[$segment] = [];
                }

                static::arraySet($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || ! array_key_exists($segment, $target)) {
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
    public static function arrayRemove(array &$array, $key)
    {
        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (! isset($array[$key]) || ! is_array($array[$key])) {
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
    public function merge($value)
    {
        $array = $this->getArrayValue($value, "Value is not mergeable.");

        foreach ($value as $key => $val) {
            $this->items = static::arraySet($this->items, $key, $val, true);
        }
    }

    /**
     * Returns array value.
     *
     * @param array $value
     * @param string $message
     * @return array
     */
    protected function getArrayValue($value, $message)
    {
        if (! is_array($value) && false === $value instanceof ArrayExtra) {
            throw new InvalidArgumentException($message);
        }

        return is_array($value) ? $value : $value->toArray();
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

    public function offsetSet($key, $value)
    {
        $this->items = static::arraySet($this->items, $key, $value, true);
    }

    public function offsetExists($key)
    {
        return static::arrayHas($this->items, $key);
    }

    public function offsetUnset($key)
    {
        static::arrayRemove($this->items, $key);
    }

    public function offsetGet($key)
    {
        return static::arrayGet($this->items, $key);
    }
}
