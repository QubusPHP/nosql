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
use Qubus\Exception\Data\TypeException;

use function array_filter;
use function strtolower;

class FilterPipe implements Pipe
{
    /** @var array $filters */
    protected array $filters = [];

    /**
     * @param array $data
     * @return array
     * @throws TypeException
     */
    public function process(array $data): array
    {
        $filters = $this->filters;
        return array_filter(array: $data, callback: function ($row) use ($filters) {
            $result = true;
            foreach ($filters as $i => $filter) {
                [$filter, $type] = $filter;
                $result = match ($type) {
                    'and' => $result && $filter($row),
                    'or' => $result || $filter($row),
                    default => throw new TypeException(message: "Filter type must be 'AND' or 'OR'.", code: 1),
                };
            }
            return $result;
        });
    }

    public function add(Closure $filter, string $type = 'AND'): void
    {
        $this->filters[] = [$filter, strtolower(string: $type)];
    }
}
