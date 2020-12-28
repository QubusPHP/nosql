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

use function array_map;

class MapperPipe implements Pipe
{
    /** @var array $mappers */
    protected array $mappers = [];

    public function process(array $data)
    {
        foreach ($this->mappers as $mapper) {
            $data = array_map($mapper, $data);
        }

        return $data;
    }

    public function add(Closure $mapper)
    {
        $this->mappers[] = $mapper;
    }
}
