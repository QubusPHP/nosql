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

class LimiterPipe implements Pipe
{
    protected ?int $limit = null;
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
        return array_slice(
            array: $data,
            offset: $this->offset,
            length: $this->limit,
            preserve_keys: true
        );
    }
}
