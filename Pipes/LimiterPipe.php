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

use function array_slice;
use function count;

class LimiterPipe implements Pipe
{
    protected int $limit = 0;
    protected int $offset = 0;

    public function setLimit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function setOffset(int $offset = 0): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function process(array $data): array
    {
        $limit = (int) $this->limit ?: count($data);
        $offset = (int) $this->offset;
        return array_slice(array: $data, offset: $offset, length: $limit);
    }
}
