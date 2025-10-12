<?php

declare(strict_types=1);

namespace Qubus\Tests\NoSql;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use Qubus\NoSql\Collection;
use Qubus\NoSql\Exceptions\InvalidJsonException;
use Qubus\NoSql\Node;

use function array_keys;
use function array_values;
use function file_put_contents;
use function json_encode;
use function range;
use function str_replace;
use function unlink;

use const JSON_PRETTY_PRINT;

class CollectionTest extends TestCase
{
    protected string $filepath;

    protected Collection $nodeq;

    /** @var array $dummyData */
    protected array $dummyData = [
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

    public function setUp(): void
    {
        $this->filepath = __DIR__ . '/db/data';
        // initialize data
        file_put_contents($this->filepath . '.json', json_encode($this->dummyData, JSON_PRETTY_PRINT));

        $this->nodeq = new Collection($this->filepath);
    }

    /**
     * @throws InvalidJsonException
     */
    public function testAll()
    {
        $result = $this->nodeq->all();
        Assert::assertEquals($result, array_values($this->dummyData));
    }

    /**
     * @throws InvalidJsonException
     */
    public function testFind()
    {
        $result = $this->nodeq->find('01K7A51JXJEFBBT89K10CA13H1');
        Assert::assertEquals([
            '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
            'email' => 'b@site.com',
            'name'  => 'B',
            'score' => 76,
        ], $result);
    }

    public function testFirst()
    {
        $result = $this->nodeq->query()->first();
        Assert::assertEquals([
            '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
            'email' => 'a@site.com',
            'name'  => 'A',
            'score' => 80,
        ], $result);
    }

    public function testGetAll()
    {
        Assert::assertEquals($this->nodeq->query()->get(), array_values($this->dummyData));
    }

    public function testFilter()
    {
        $result = $this->nodeq->where(function ($row) {
            return $row['score'] > 90;
        })->get();

        Assert::assertEquals([
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testMap()
    {
        $result = $this->nodeq->map(function ($row) {
            return [
                'x' => $row['score'],
            ];
        })->get();

        Assert::assertEquals([
            ['x' => 80],
            ['x' => 76],
            ['x' => 95],
        ], $result);
    }

    public function testGetSomeColumns()
    {
        $result = $this->nodeq->query()->get(['email', 'name']);
        Assert::assertEquals([
            [
                'email' => 'a@site.com',
                'name'  => 'A',
            ],
            [
                'email' => 'b@site.com',
                'name'  => 'B',
            ],
            [
                'email' => 'c@site.com',
                'name'  => 'C',
            ],
        ], $result);
    }

    /**
     * @throws TypeException
     */
    public function testSortAscending()
    {
        $result = $this->nodeq->query()->sortBy('score', 'asc')->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    /**
     * @throws TypeException
     */
    public function testSortDescending()
    {
        $result = $this->nodeq->query()->sortBy('score', 'desc')->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $result);
    }

    public function testSkip()
    {
        $result = $this->nodeq->query()->skip(1)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testTake()
    {
        $result = $this->nodeq->query()->take(1, 1)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $result);
    }

    public function testCount()
    {
        Assert::assertEquals(3, $this->nodeq->count());
    }

    public function testSum()
    {
        Assert::assertEquals(76 + 80 + 95, $this->nodeq->sum('score'));
    }

    public function testAvg()
    {
        Assert::assertEquals((76 + 80 + 95) / 3, $this->nodeq->avg('score'));
    }

    public function testMin()
    {
        Assert::assertEquals(76, $this->nodeq->min('score'));
    }

    public function testMax()
    {
        Assert::assertEquals(95, $this->nodeq->max('score'));
    }

    public function testLists()
    {
        Assert::assertEquals([80, 76, 95], $this->nodeq->lists('score'));
    }

    public function testListsWithKey()
    {
        $result = $this->nodeq->lists('score', 'email');
        Assert::assertEquals([
            'a@site.com' => 80,
            'b@site.com' => 76,
            'c@site.com' => 95,
        ], $result);
    }

    public function testGetWhereEquals()
    {
        $result = $this->nodeq->where('name', 'C')->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testGetOrWhere()
    {
        $result = $this->nodeq->where('name', 'C')->orWhere('name', 'B')->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testGetWhereBiggerThan()
    {
        $result = $this->nodeq->where('score', '>', 80)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testGetWhereBiggerThanEquals()
    {
        $result = $this->nodeq->where('score', '>=', 80)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testGetWhereLowerThan()
    {
        $result = $this->nodeq->where('score', '<', 80)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $result);
    }

    public function testGetWhereLowerThanEquals()
    {
        $result = $this->nodeq->where('score', '<=', 80)->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $result);
    }

    public function testGetWhereIn()
    {
        $result = $this->nodeq->where('score', 'in', [80])->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
        ], $result);
    }

    public function testGetWhereNotIn()
    {
        $result = $this->nodeq->where('score', 'not in', [80])->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    public function testGetWhereMatch()
    {
        $result = $this->nodeq->where('email', 'match', '/^b@/')->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $result);
    }

    public function testGetWhereBetween()
    {
        $result = $this->nodeq->where('score', 'between', [80, 95])->get();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 95,
            ],
        ], $result);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function testInsert()
    {
        $this->nodeq->insert([
            'test' => 'foo',
        ]);

        $lastInsertId = $this->nodeq->lastInsertId();

        Assert::assertEquals(4, $this->nodeq->count());
        $data = $this->nodeq->where('test', 'foo')->first();
        Assert::assertEquals(['_id', 'test'], array_keys($data));
        Assert::assertEquals('foo', $data['test']);
        Assert::assertEquals($data['_id'], $lastInsertId);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     * @throws Exception
     */
    public function testInsertWithTransaction()
    {
        $this->nodeq->transaction(function (Collection $db) {
            $db->insert([
                'test_transaction' => 'transaction',
            ]);
        });

        $lastInsertId = $this->nodeq->lastInsertId();

        Assert::assertEquals(4, $this->nodeq->count());
        $data = $this->nodeq->where('test_transaction', 'transaction')->first();
        Assert::assertEquals(['_id', 'test_transaction'], array_keys($data));
        Assert::assertEquals('transaction', $data['test_transaction']);
        Assert::assertEquals($data['_id'], $lastInsertId);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function testInserts()
    {
        $this->nodeq->inserts([
            ['test' => 'foo'],
            ['test' => 'bar'],
            ['test' => 'baz'],
        ]);

        Assert::assertEquals(6, $this->nodeq->count());
    }

    /**
     * @throws InvalidJsonException
     */
    public function testUpdate()
    {
        $this->nodeq->where('score', '>=', 80)->update([
            'score' => 90,
        ]);

        Assert::assertEquals([
            [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 90,
            ],
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id'   => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'email' => 'c@site.com',
                'name'  => 'C',
                'score' => 90,
            ],
        ], $this->nodeq->all());
    }

    /**
     * @throws InvalidJsonException
     */
    public function testUpdateWithFilterMapAndSave()
    {
        $this->nodeq->where('score', '>=', 80)->map(function ($row) {
            return [
                'x' => $row['score'],
            ];
        })->save();

        Assert::assertEquals([
            [
                '_id' => '01K7A51H9EE1PSAQ3TW8897XTG',
                'x'   => 80,
            ],
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
            [
                '_id' => '01K7A81ZRBEAV91ACBN8K0N4W0',
                'x'   => 95,
            ],
        ], $this->nodeq->all());
    }

    /**
     * @throws InvalidJsonException
     */
    public function testDelete()
    {
        $this->nodeq->where('score', '>=', 80)->delete();
        Assert::assertEquals([
            [
                '_id'   => '01K7A51JXJEFBBT89K10CA13H1',
                'email' => 'b@site.com',
                'name'  => 'B',
                'score' => 76,
            ],
        ], $this->nodeq->all());
    }

    /**
     * @throws TypeException
     */
    public function testWithOne()
    {
        $result = $this->nodeq->withOne($this->nodeq, 'other', 'email', '=', 'email')->first();
        Assert::assertEquals([
            '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
            'email' => 'a@site.com',
            'name'  => 'A',
            'score' => 80,
            'other' => [
                '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                'email' => 'a@site.com',
                'name'  => 'A',
                'score' => 80,
            ],
        ], $result);
    }

    /**
     * @throws TypeException
     */
    public function testWithMany()
    {
        $result = $this->nodeq->withMany($this->nodeq, 'other', 'email', '=', 'email')->first();
        Assert::assertEquals([
            '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
            'email' => 'a@site.com',
            'name'  => 'A',
            'score' => 80,
            'other' => [
                [
                    '_id'   => '01K7A51H9EE1PSAQ3TW8897XTG',
                    'email' => 'a@site.com',
                    'name'  => 'A',
                    'score' => 80,
                ],
            ],
        ], $result);
    }

    /**
     * @throws TypeException
     */
    public function testSelectAs()
    {
        $result = $this->nodeq->query()->withOne($this->nodeq, 'other', 'email', '=', 'email')->first([
            'email',
            'other.email:other_email',
        ]);

        Assert::assertEquals([
            'email'       => 'a@site.com',
            'other_email' => 'a@site.com',
        ], $result);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function testMacro()
    {
        // delete current db
        $this->tearDown();

        $db = new Collection($this->filepath);

        // Register macro
        $db->macro('replace', function ($query, $key, array $replacers) {
            $keys = (array) $key;

            return $query->map(function ($item) use ($keys, $replacers) {
                foreach ($keys as $key) {
                    if (isset($item[$key])) {
                        $item[$key] = str_replace(array_keys($replacers), array_values($replacers), $item[$key]);
                    }
                }
                return $item;
            });
        });

        // Insert items
        foreach (range(1, 10) as $n) {
            $db->insert([
                'number' => (string) $n,
            ]);
        }

        // Use macro within collection
        $result = $db->replace('number', [
            '1' => 'one',
            '2' => 'two',
        ])->get();

        Assert::assertEquals('one', $result[0]['number']);
        Assert::assertEquals('two', $result[1]['number']);
        Assert::assertEquals('one0', $result[9]['number']);

        // Use macro in query chain
        $result2 = $db->query()->replace('number', [
            '1' => 'one',
            '2' => 'two',
        ])->get();

        Assert::assertEquals('one', $result2[0]['number']);
        Assert::assertEquals('two', $result2[1]['number']);
        Assert::assertEquals('one0', $result2[9]['number']);
    }

    /**
     * @throws InvalidJsonException
     * @throws TypeException
     */
    public function testGlobalMacro()
    {
        Node::macro('replace', function ($query, $key, array $replacers) {
            $keys = (array) $key;

            return $query->map(function ($item) use ($keys, $replacers) {
                foreach ($keys as $key) {
                    if (isset($item[$key])) {
                        $item[$key] = str_replace(array_keys($replacers), array_values($replacers), $item[$key]);
                    }
                }
                return $item;
            });
        });

        $this->tearDown();
        $db = Node::open($this->filepath, ['file_extension' => '.json']);

        // Insert items
        foreach (range(1, 10) as $n) {
            $db->insert([
                'number' => (string) $n,
            ]);
        }

        $result = $db->replace('number', [
            '1' => 'one',
            '2' => 'two',
        ])->get();

        Assert::assertEquals('one', $result[0]['number']);
        Assert::assertEquals('two', $result[1]['number']);
        Assert::assertEquals('one0', $result[9]['number']);
    }

    public function tearDown(): void
    {
        unlink($this->filepath . '.json');
    }
}
