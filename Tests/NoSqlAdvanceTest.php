<?php

declare(strict_types=1);

namespace Qubus\Tests\NoSql;

use Qubus\Exception\Data\TypeException;
use Qubus\NoSql\ArrayExtra;
use Qubus\NoSql\Collection;
use Qubus\NoSql\Exceptions\InvalidJsonException;
use Qubus\NoSql\Node;
use RuntimeException;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

use const JSON_PRETTY_PRINT;

class NoSqlAdvanceTest extends \PHPUnit\Framework\TestCase
{
    protected string $filepath;

    protected Collection $collection;

    protected array $data = [
        '01K7A51H9EE1PSAQ3TW8897XTG' => [
            '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
            'email' => 'a@site.com',
            'name'  => 'A',
            'score' => 80,
        ],
        '01K7A51JXJEFBBT89K10CA13H1' => [
            '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
            'email' => 'b@site.com',
            'name'  => 'B',
            'score' => 76,
        ],
        '01K7A81ZRBEAV91ACBN8K0N4W0' => [
            '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
            'email' => 'c@site.com',
            'name'  => 'C',
            'score' => 95,
        ],
    ];

    protected function setUp(): void
    {
        $this->filepath = sys_get_temp_dir() . '/qubus-nosql-' . bin2hex(random_bytes(8));
        file_put_contents($this->filepath . '.json', json_encode($this->data, JSON_PRETTY_PRINT));
        $this->collection = new Collection($this->filepath);
    }

    public function testQueryGetPropagatesInvalidJsonException(): void
    {
        file_put_contents($this->filepath . '.json', '{invalid');

        $this->expectException(InvalidJsonException::class);
        $this->collection->query()->get();
    }

    public function testScalarJsonIsRejectedAsDatabaseData(): void
    {
        file_put_contents($this->filepath . '.json', 'true');

        $this->expectException(InvalidJsonException::class);
        $this->collection->all();
    }

    public function testFailedEncodingDoesNotOverwriteExistingData(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        try {
            $this->collection->insert(['recursive' => $recursive]);
            self::fail('A recursive value should not be JSON encodable.');
        } catch (InvalidJsonException) {
            self::assertSame($this->data, $this->collection->loadData());
            self::assertNull($this->collection->lastInsertId());
        }
    }

    public function testInsertingEventCanSafelyChangeRecordId(): void
    {
        $id = '01K7A81ZRBEAV91ACBN8K0N4W1';
        $this->collection->on(Collection::INSERTING, function (ArrayExtra $record) use ($id): void {
            $record['_id'] = $id;
        });

        $record = $this->collection->insert(['name' => 'event-id']);

        self::assertSame($id, $record['_id']);
        self::assertSame($id, $this->collection->lastInsertId());
        self::assertSame($record, $this->collection->find($id));
    }

    public function testEmptyTransactionDataIsVisibleAndCommitted(): void
    {
        $this->collection->begin();
        $this->collection->truncate();

        self::assertSame([], $this->collection->all());

        $this->collection->commit();
        self::assertSame([], $this->collection->all());
    }

    public function testTransactionRollsBackAndRethrowsOriginalThrowable(): void
    {
        try {
            $this->collection->transaction(function (Collection $collection): void {
                $collection->truncate();
                throw new RuntimeException('transaction failed');
            });
            self::fail('The transaction exception should be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('transaction failed', $exception->getMessage());
        }

        self::assertFalse($this->collection->isModeTransaction());
        self::assertSame($this->data, $this->collection->loadData());
    }

    public function testBulkInsertDoesNotCommitOuterTransaction(): void
    {
        $this->collection->begin();
        $this->collection->inserts([
            ['name' => 'D'],
            ['name' => 'E'],
        ]);

        self::assertTrue($this->collection->isModeTransaction());
        self::assertCount(5, $this->collection->all());

        $this->collection->rollback();
        self::assertCount(3, $this->collection->all());
    }

    public function testOrWhereCanBeTheFirstFilter(): void
    {
        $rows = $this->collection->query()->orWhere('name', 'B')->get();

        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]['name']);
    }

    public function testNotEqualOperatorIsAvailable(): void
    {
        $rows = $this->collection->where('name', '!=', 'B')->get();

        self::assertSame(['A', 'C'], [$rows[0]['name'], $rows[1]['name']]);
    }

    public function testUnknownExecutionTypeHasAUsefulException(): void
    {
        $this->expectException(TypeException::class);
        $this->collection->execute($this->collection->query(), 'unknown');
    }

    public function testTakeZeroReturnsNoRows(): void
    {
        self::assertSame([], $this->collection->take(0)->get());
    }

    public function testLimitPreservesNumericKeysForUpdates(): void
    {
        file_put_contents($this->filepath . '.json', json_encode([
            ['_id' => 'first', 'name' => 'A'],
            ['_id' => 'second', 'name' => 'B'],
        ]));

        $this->collection->take(1, 1)->update(['name' => 'updated']);

        self::assertSame('A', $this->collection->find(0)['name']);
        self::assertSame('updated', $this->collection->find(1)['name']);
    }

    public function testCollectionFilterAndSortDelegatesAreUsable(): void
    {
        $rows = $this->collection
            ->filter(fn (ArrayExtra $row): bool => $row['score'] >= 80)
            ->sort(fn (ArrayExtra $row): int => $row['score'], 'desc')
            ->get();

        self::assertSame(['C', 'A'], [$rows[0]['name'], $rows[1]['name']]);
    }

    public function testSelectAliasOverwritesAnExistingColumn(): void
    {
        $row = $this->collection->query()->first(['name:email']);

        self::assertSame(['email' => 'A'], $row);
    }

    public function testQueryRelationIsClonedForEachParentRow(): void
    {
        $relation = $this->collection->where('score', '>=', 80);
        $rows = $this->collection
            ->withMany($relation, 'matches', 'email', '=', 'email')
            ->get();

        self::assertCount(1, $rows[0]['matches']);
        self::assertSame([], $rows[1]['matches']);
        self::assertCount(1, $rows[2]['matches']);
        self::assertSame(2, $relation->count());
    }

    public function testSavingMappedIdMovesRecordWithoutLeakingInternalKey(): void
    {
        $oldId = '01K7A51H9EE1PSAQ3TW8897XTG';
        $newId = 'renamed-record';

        $this->collection->where('_id', $oldId)->map(function (ArrayExtra $row) use ($newId): ArrayExtra {
            $row['_id'] = $newId;
            return $row;
        })->save();

        self::assertNull($this->collection->find($oldId));
        self::assertSame($newId, $this->collection->find($newId)['_id']);
        self::assertArrayNotHasKey('_old', $this->collection->find($newId));
    }

    public function testAggregatesHaveSafeEmptyCollectionResults(): void
    {
        $this->collection->truncate();

        self::assertSame(0, $this->collection->avg('score'));
        self::assertNull($this->collection->min('score'));
        self::assertNull($this->collection->max('score'));
    }

    public function testArrayExtraAppendAndMissingNestedUnset(): void
    {
        $array = new ArrayExtra(['existing' => 'value']);
        $array[] = 'appended';
        $array[2] = 'numeric';
        unset($array['missing.nested']);
        unset($array[2]);

        self::assertSame(['existing' => 'value', 0 => 'appended'], $array->toArray());
    }

    public function testNodeCachesCollectionsByResolvedFilename(): void
    {
        Node::clear();

        $json = Node::open($this->filepath);
        $custom = Node::open($this->filepath, ['file_extension' => '.data']);

        self::assertNotSame($json, $custom);
        self::assertSame($json, Node::open($this->filepath, ['file_extension' => '.json']));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->filepath . '.json')) {
            unlink($this->filepath . '.json');
        }
    }
}
