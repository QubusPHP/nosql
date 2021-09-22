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

namespace Qubus\NoSql\Pipes;

use Closure;
use Qubus\Exception\Data\TypeException;

use function array_filter;
use function strtolower;

class FilterPipe implements Pipe
{
    /** @var array $filters */
    protected array $filters = [];

    public function process(array $data): array
    {
        $filters = $this->filters;
        return array_filter($data, function ($row) use ($filters) {
            $result = true;
            foreach ($filters as $i => $filter) {
                [$filter, $type] = $filter;
                switch ($type) {
                    case 'and':
                        $result = $result && $filter($row);
                        break;
                    case 'or':
                        $result = $result || $filter($row);
                        break;
                    default:
                        throw new TypeException("Filter type must be 'AND' or 'OR'.", 1);
                }
            }
            return $result;
        });
    }

    public function add(Closure $filter, $type = 'AND'): void
    {
        $this->filters[] = [$filter, strtolower($type)];
    }
}
