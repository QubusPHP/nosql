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

class NodeQ
{
    /** @var array $collections */
    protected static array $collections = [];

    /** @var array $macros */
    protected static array $macros = [];

    /**
     * Opens a file.
     *
     * @param string $file The file to open.
     * @param array $options
     * @return Collection
     */
    public static function open(string $file, array $options = []): Collection
    {
        if (! isset(static::$collections[$file])) {
            static::$collections[$file] = new Collection($file, $options);
        }

        $collection = static::$collections[$file];

        // Register macros
        foreach (static::$macros as $name => $callback) {
            $collection->macro($name, $callback);
        }

        return $collection;
    }

    /**
     * Create a macro.
     *
     * @param string $name Name of the macro.
     */
    public static function macro(string $name, callable $callback): void
    {
        static::$macros[$name] = $callback;
    }
}
