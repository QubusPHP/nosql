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

namespace Qubus\NoSql\Pipes;

use Closure;

use function array_keys;
use function array_map;
use function arsort;
use function asort;
use function strtolower;

class SorterPipe implements Pipe
{
    protected Closure $value;

    protected string $ascending;

    public function __construct(Closure $value, string $ascending = 'asc')
    {
        $this->value = $value;
        $this->ascending = strtolower(string: $ascending);
    }

    public function process(array $data): array
    {
        return $this->sort(array: $data, value: $this->value, ascending: $this->ascending);
    }

    public function sort(array $array, Closure $value, string $ascending): array
    {
        $values = array_map(callback: function ($row) use ($value) {
            return $value($row);
        }, array: $array);

        switch ($ascending) {
            case 'asc':
                asort($values);
                break;
            case 'desc':
                arsort($values);
                break;
        }

        $keys = array_keys(array: $values);

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $array[$key];
        }
        return $result;
    }
}
