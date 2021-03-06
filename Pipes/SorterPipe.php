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

namespace Qubus\NoSql\Pipes;

use Closure;

use function array_keys;
use function array_map;
use function arsort;
use function asort;
use function strtolower;

class SorterPipe implements Pipe
{
    /** @var Closure $value */
    protected $value;

    /** @var string $ascending */
    protected $ascending;

    public function __construct(Closure $value, $ascending = 'asc')
    {
        $this->value = $value;
        $this->ascending = strtolower($ascending);
    }

    public function process(array $data)
    {
        return $this->sort($data, $this->value, $this->ascending);
    }

    public function sort($array, $value, $ascending)
    {
        $values = array_map(function ($row) use ($value) {
            return $value($row);
        }, $array);

        switch ($ascending) {
            case 'asc':
                asort($values);
                break;
            case 'desc':
                arsort($values);
                break;
        }

        $keys = array_keys($values);

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $array[$key];
        }
        return $result;
    }
}
