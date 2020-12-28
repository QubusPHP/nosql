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

use function array_slice;
use function count;

class LimiterPipe implements Pipe
{
    protected int $limit = 0;
    protected int $offset = 0;

    public function setLimit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function setOffset(int $offset = 0)
    {
        $this->offset = $offset;

        return $this;
    }

    public function process(array $data)
    {
        $limit = (int) $this->limit ?: count($data);
        $offset = (int) $this->offset;
        return array_slice($data, $offset, $limit);
    }
}
