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

namespace Qubus\Tests\NoSql;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Qubus\NoSql\Collection;
use Qubus\NoSql\DB;

use function array_keys;
use function array_values;
use function file_put_contents;
use function json_encode;
use function range;
use function str_replace;
use function strlen;
use function substr;
use function unlink;

use const JSON_PRETTY_PRINT;

class CollectionTest extends TestCase
{
    protected string $filepath;

    protected Collection $db;

    /** @var array $dummyData */
    protected array $dummyData = [
        "58745c13ad585" => [
            "_id"   => "58745c13ad585",
            "email" => "a@site.com",
            "name"  => "A",
            "score" => 80,
        ],
        "58745c19b4c51" => [
            "_id"   => "58745c19b4c51",
            "email" => "b@site.com",
            "name"  => "B",
            "score" => 76,
        ],
        "58745c1ef0b13" => [
            "_id"   => "58745c1ef0b13",
            "email" => "c@site.com",
            "name"  => "C",
            "score" => 95,
        ],
    ];

    public function setUp(): void
    {
        $this->filepath = __DIR__ . '/db/data';
        // initialize data
        file_put_contents($this->filepath . '.json', json_encode($this->dummyData, JSON_PRETTY_PRINT));

        $this->db = new Collection($this->filepath);
    }

    public function testAll()
    {
        $result = $this->db->all();
        Assert::assertEquals($result, array_values($this->dummyData));
    }

    public function testFind()
    {
        $result = $this->db->find('58745c19b4c51');
        Assert::assertEquals($result, [
            "_id"   => "58745c19b4c51",
            "email" => "b@site.com",
            "name"  => "B",
            "score" => 76,
        ]);
    }

    public function testFirst()
    {
        $result = $this->db->query()->first();
        Assert::assertEquals($result, [
            "_id"   => "58745c13ad585",
            "email" => "a@site.com",
            "name"  => "A",
            "score" => 80,
        ]);
    }

    public function testGetAll()
    {
        Assert::assertEquals($this->db->query()->get(), array_values($this->dummyData));
    }

    public function testFilter()
    {
        $result = $this->db->where(function ($row) {
            return $row['score'] > 90;
        })->get();

        Assert::assertEquals($result, [
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testMap()
    {
        $result = $this->db->map(function ($row) {
            return [
                'x' => $row['score'],
            ];
        })->get();

        Assert::assertEquals($result, [
            ["x" => 80],
            ["x" => 76],
            ["x" => 95],
        ]);
    }

    public function testGetSomeColumns()
    {
        $result = $this->db->query()->get(['email', 'name']);
        Assert::assertEquals($result, [
            [
                "email" => "a@site.com",
                "name"  => "A",
            ],
            [
                "email" => "b@site.com",
                "name"  => "B",
            ],
            [
                "email" => "c@site.com",
                "name"  => "C",
            ],
        ]);
    }

    public function testSortAscending()
    {
        $result = $this->db->query()->sortBy('score', 'asc')->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testSortDescending()
    {
        $result = $this->db->query()->sortBy('score', 'desc')->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testSkip()
    {
        $result = $this->db->query()->skip(1)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testTake()
    {
        $result = $this->db->query()->take(1, 1)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testCount()
    {
        Assert::assertEquals($this->db->count(), 3);
    }

    public function testSum()
    {
        Assert::assertEquals($this->db->sum('score'), 76 + 80 + 95);
    }

    public function testAvg()
    {
        Assert::assertEquals($this->db->avg('score'), (76 + 80 + 95) / 3);
    }

    public function testMin()
    {
        Assert::assertEquals($this->db->min('score'), 76);
    }

    public function testMax()
    {
        Assert::assertEquals($this->db->max('score'), 95);
    }

    public function testLists()
    {
        Assert::assertEquals($this->db->lists('score'), [80, 76, 95]);
    }

    public function testListsWithKey()
    {
        $result = $this->db->lists('score', 'email');
        Assert::assertEquals($result, [
            'a@site.com' => 80,
            'b@site.com' => 76,
            'c@site.com' => 95,
        ]);
    }

    public function testGetWhereEquals()
    {
        $result = $this->db->where('name', 'C')->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testGetOrWhere()
    {
        $result = $this->db->where('name', 'C')->orWhere('name', 'B')->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testGetWhereBiggerThan()
    {
        $result = $this->db->where('score', '>', 80)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testGetWhereBiggerThanEquals()
    {
        $result = $this->db->where('score', '>=', 80)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testGetWhereLowerThan()
    {
        $result = $this->db->where('score', '<', 80)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testGetWhereLowerThanEquals()
    {
        $result = $this->db->where('score', '<=', 80)->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testGetWhereIn()
    {
        $result = $this->db->where('score', 'in', [80])->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
        ]);
    }

    public function testGetWhereNotIn()
    {
        $result = $this->db->where('score', 'not in', [80])->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testGetWhereMatch()
    {
        $result = $this->db->where('email', 'match', '/^b@/')->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testGetWhereBetween()
    {
        $result = $this->db->where('score', 'between', [80, 95])->get();
        Assert::assertEquals($result, [
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 95,
            ],
        ]);
    }

    public function testInsert()
    {
        $this->db->insert([
            'test' => 'foo',
        ]);

        $lastInsertId = $this->db->lastInsertId();

        Assert::assertEquals($this->db->count(), 4);
        $data = $this->db->where('test', 'foo')->first();
        Assert::assertEquals(array_keys($data), ['_id', 'test']);
        Assert::assertEquals($data['test'], 'foo');
        Assert::assertEquals($data['_id'], $lastInsertId);
    }

    public function testInsertWithTransaction()
    {
        $this->db->transaction(function (Collection $db) {
            $db->insert([
                'test_transaction' => 'transaction',
            ]);
        });

        $lastInsertId = $this->db->lastInsertId();

        Assert::assertEquals($this->db->count(), 4);
        $data = $this->db->where('test_transaction', 'transaction')->first();
        Assert::assertEquals(array_keys($data), ['_id', 'test_transaction']);
        Assert::assertEquals($data['test_transaction'], 'transaction');
        Assert::assertEquals($data['_id'], $lastInsertId);
    }

    public function testInserts()
    {
        $this->db->inserts([
            ['test' => 'foo'],
            ['test' => 'bar'],
            ['test' => 'baz'],
        ]);

        Assert::assertEquals($this->db->count(), 6);
    }

    public function testUpdate()
    {
        $this->db->where('score', '>=', 80)->update([
            'score' => 90,
        ]);

        Assert::assertEquals($this->db->all(), [
            [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 90,
            ],
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id"   => "58745c1ef0b13",
                "email" => "c@site.com",
                "name"  => "C",
                "score" => 90,
            ],
        ]);
    }

    public function testUpdateWithFilterMapAndSave()
    {
        $this->db->where('score', '>=', 80)->map(function ($row) {
            return [
                'x' => $row['score'],
            ];
        })->save();

        Assert::assertEquals($this->db->all(), [
            [
                "_id" => "58745c13ad585",
                "x"   => 80,
            ],
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
            [
                "_id" => "58745c1ef0b13",
                "x"   => 95,
            ],
        ]);
    }

    public function testDelete()
    {
        $this->db->where('score', '>=', 80)->delete();
        Assert::assertEquals($this->db->all(), [
            [
                "_id"   => "58745c19b4c51",
                "email" => "b@site.com",
                "name"  => "B",
                "score" => 76,
            ],
        ]);
    }

    public function testWithOne()
    {
        $result = $this->db->withOne($this->db, 'other', 'email', '=', 'email')->first();
        Assert::assertEquals($result, [
            "_id"   => "58745c13ad585",
            "email" => "a@site.com",
            "name"  => "A",
            "score" => 80,
            'other' => [
                "_id"   => "58745c13ad585",
                "email" => "a@site.com",
                "name"  => "A",
                "score" => 80,
            ],
        ]);
    }

    public function testWithMany()
    {
        $result = $this->db->withMany($this->db, 'other', 'email', '=', 'email')->first();
        Assert::assertEquals($result, [
            "_id"   => "58745c13ad585",
            "email" => "a@site.com",
            "name"  => "A",
            "score" => 80,
            'other' => [
                [
                    "_id"   => "58745c13ad585",
                    "email" => "a@site.com",
                    "name"  => "A",
                    "score" => 80,
                ],
            ],
        ]);
    }

    public function testSelectAs()
    {
        $result = $this->db->query()->withOne($this->db, 'other', 'email', '=', 'email')->first([
            'email',
            'other.email:other_email',
        ]);

        Assert::assertEquals($result, [
            "email"       => "a@site.com",
            "other_email" => "a@site.com",
        ]);
    }

    public function testMoreEntropy()
    {
        $db = new Collection($this->filepath, [
            'more_entropy' => true,
        ]);

        $data = $db->insert([
            'label' => 'Test more entropy',
        ]);

        Assert::assertEquals(strlen($data['_id']), 23);
    }

    public function testKeyPrefix()
    {
        $db = new Collection($this->filepath, [
            'key_prefix' => 'foobar',
        ]);

        $data = $db->insert([
            'label' => 'Test key prefix',
        ]);

        Assert::assertEquals(substr($data['_id'], 0, 6), "foobar");
    }

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

        Assert::assertEquals($result[0]['number'], 'one');
        Assert::assertEquals($result[1]['number'], 'two');
        Assert::assertEquals($result[9]['number'], 'one0');

        // Use macro in query chain
        $result2 = $db->query()->replace('number', [
            '1' => 'one',
            '2' => 'two',
        ])->get();

        Assert::assertEquals($result2[0]['number'], 'one');
        Assert::assertEquals($result2[1]['number'], 'two');
        Assert::assertEquals($result2[9]['number'], 'one0');
    }

    public function testGlobalMacro()
    {
        DB::macro('replace', function ($query, $key, array $replacers) {
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
        $db = DB::open($this->filepath, ['file_extension' => '.json']);

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

        Assert::assertEquals($result[0]['number'], 'one');
        Assert::assertEquals($result[1]['number'], 'two');
        Assert::assertEquals($result[9]['number'], 'one0');
    }

    public function tearDown(): void
    {
        unlink($this->filepath . '.json');
    }
}
