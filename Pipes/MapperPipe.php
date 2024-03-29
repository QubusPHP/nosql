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

use function array_map;

class MapperPipe implements Pipe
{
    /** @var array $mappers */
    protected array $mappers = [];

    public function process(array $data): array
    {
        foreach ($this->mappers as $mapper) {
            $data = array_map(callback: $mapper, array: $data);
        }

        return $data;
    }

    public function add(Closure $mapper): void
    {
        $this->mappers[] = $mapper;
    }
}
