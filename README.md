
NoSQL - Flat File JSON Database Management System
=================================================

NoSQL is a fork of [LaciDb](https://github.com/emsifa/laci-db).

## Overview

NoSQL is a flat file Database with a JSON storage format. Due to the JSON format, NoSQL is *schemaless* like any other NoSQLs. A record can have different columns.

In NoSQL there is no table, it is a collection. A collection in NoSQL represents a file that holds multiple records (in JSON format).

In NoSQL, each query will open file => query execution (select|insert|update|delete) => file is closed.

NoSQL is not for:

* Saving a large database with lots of data.
* Storing databases that require a high level of security.

NoSQL created for:

* Handling small data such as settings, queues or other small data.
* For those of you who want a portable database that is easy to import/export, version control and backup.
* For those of you who want a database that is easy to edit yourself without using special software.

## Requirements

* PHP 7.4+

## Installation

```
composer require qubus/nosql
```

## Instantiate

```php
require 'vendor/autoload.php';

use Qubus\NoSql\Collection;

$collection = new Collection(__DIR__.'/users'); // users is a file, so it will look for users.json

// Second parameter of Collection takes an array of options; below are the default options:

$options = [
    'file_extension' => '.json',
    'save_format' => JSON_PRETTY_PRINT,
    'key_prefix' => '',
    'more_entropy' => false,
];
```

## How it Works?

The way NoSQL works is basically just flowing the array of `json_decode` results into the 'pipes' which functions as *filtering*, *mapping*, *sorting* and *limiting* until finally the results will be executed to retrieve the value, change its value or discard (read: deleted).

The following is an explanation of the process:

### Filtering

To do filtering you can use the `where` and` orWhere` methods. Both methods can accept the `Closure` parameter or some `key, operator, value` parameter.

### Mapping

Mapping is used to form a new value on each filtered record.

Here are some methods for mapping records:

#### `map(Closure $mapper)`

For mapping records in the filtered collection.

#### `select(array $columns)`

Mapping records to retrieve only certain columns.

#### `withOne(Collection|Query $relation, $key, $otherKey, $operator, $thisKey)`

To take a 1:1 relation.

#### `withMany(Collection|Query $relation, $key, $otherKey, $operator, $thisKey)`

To take a 1:n relation.

### Sorting

Sorting is used to sort data that has been filtered and mapped. To do the sorting you can use the `sortBy($key, $ascending)` method. The parameter `$key` can be a string key/column to sort or `Closure` if you want to sort based on the computed value first.

### Limiting/Taking

After the data has been filtered, mapped, and sorted, you can cut and retrieve some of the data using the `skip($offset)` or `take($limit, $offset)` method.

### Executing

After filtering, mapping, sorting, and setting aside, the next step is to execute the results.

Here are some methods for executing:

#### `get(array $select = [])`

Fetching a set of records in collection. If you want to retrieve a specific column define the column in the `$select` array.

#### `first(array $select = [])`

Fetch (one) record in a collection. If you want to retrieve a specific column define the column in the `$select` array.

#### `count()` 

Count all elements in the collection based on mapping criteria.

#### `sum($key)` 

Fetch the total key specified in the collection.

#### `avg($key)` 

Take the average of certain keys in a collection.

#### `min($key)` 

Fetches the lowest value of a specific key in a collection.

#### `max($key)` 

Fetches the highest value of a specific key in a collection.

#### `lists($key, $resultKey = null)` 

Collect and retrieve specific keys into the array on the collection.

#### `insert(array $data)` 

Insert new data into the collection.

#### `inserts(array $listData)` 

Batch insert new data into the collection. Note: `insert` and `inserts` cannot be performed after the query is filtered or mapped.

#### `update(array $newData)` 

Updates the data on records in a filtered and mapped collection.

#### `save()` 

Similar to `update`. Except that `save` will save records based on the mapping results, not based on `$newData` as in `update`.

#### `delete()` 

Clears data in filtered and mapped collections.

#### `truncate()` 

Erases all data. No need for filtering and mapping beforehand.

## Examples

### Insert Data

```php
$user = $collection->insert([
    'name' => 'John Doe',
    'email' => 'johndoe@mail.com',
    'password' => password_hash('password', PASSWORD_BCRYPT)
]);
```

`$user` will return an array like this:

```php
[
    '_id' => '58745c13ad585',
    'name' => 'John Doe',
    'email' => 'johndoe@mail.com',
    'password' => '$2y$10$eMF03850wE6uII7UeujyjOU5Q2XLWz0QEZ1A9yiKPjbo3sA4qYh1m'
]
```

> '_id' is `uniqid()`

### Find Single Record By ID

```php
$user = $collection->find('58745c13ad585');
```

### Find One

```php
$user = $collection->where('email', 'johndoe@mail.com')->first();
```

### Select All

```php
$data = $collection->all();
```

### Update

```php
$collection->where('email', 'johndoe@mail.com')->update([
    'name' => 'John',
    'sex' => 'male'
]);
```

> Return value is count affected records

### Delete

```php
$collection->where('email', 'johndoe@mail.com')->delete();
```

> Return value is count affected records

### Multiple Inserts

```php
$bookCollection = new Collection('db/books');

$bookCollection->inserts([
    [
        'title' => 'Foobar',
        'published_at' => '2016-02-23',
        'author' => [
            'name' => 'John Doe',
            'email' => 'johndoe@mail.com'
        ],
        'star' => 3,
        'views' => 100
    ],
    [
        'title' => 'Bazqux',
        'published_at' => '2014-01-10',
        'author' => [
            'name' => 'Jane Doe',
            'email' => 'janedoe@mail.com'
        ],
        'star' => 5,
        'views' => 56
    ],
    [
        'title' => 'Lorem Ipsum',
        'published_at' => '2013-05-12',
        'author' => [
            'name' => 'Jane Doe',
            'email' => 'janedoe@mail.com'
        ],
        'star' => 4,
        'views' => 96
    ],
]);

```

### Find Where

```php
// select * from books.json where author[name] = 'Jane Doe'
$bookCollection->where('author.name', 'Jane Doe')->get();

// select * from books.json where star > 3
$bookCollection->where('star', '>', 3)->get();

// select * from books.json where star > 3 AND author[name] = 'Jane Doe'
$bookCollection->where('star', '>', 3)->where('author.name', 'Jane Doe')->get();

// select * from books.json where star > 3 OR author[name] = 'Jane Doe'
$bookCollection->where('star', '>', 3)->orWhere('author.name', 'Jane Doe')->get();

// select * from books.json where (star > 3 OR author[name] = 'Jane Doe')
$bookCollection->where(function($book) {
    return $book['star'] > 3 OR $book['author.name'] == 'Jane Doe';
})->get();
```

> Operator can be '=', '<', '<=', '>', '>=', 'in', 'not in', 'between', 'match'.

### Fetching Specific Columns/Keys

```php
// select author, title from books.json where star > 3
$bookCollection->where('star', '>', 3)->get(['author.name', 'title']);
```

### Alias Column/Key

```php
// select author[name] as author_name, title from books.json where star > 3
$bookCollection->where('star', '>', 3)->get(['author.name:author_name', 'title']);
```

### Mapping

```php
$bookCollection->map(function($row) {
    $row['score'] = $row['star'] + $row['views'];
    return $row;
})
->sortBy('score', 'desc')
->get();
```

### Sorting

```php
// select * from books.json order by star asc
$bookCollection->sortBy('star')->get();

// select * from books.json order by star desc
$bookCollection->sortBy('star', 'desc')->get();

// sorting calculated value
$bookCollection->sortBy(function($row) {
    return $row['star'] + $row['views'];
}, 'desc')->get();
```

### Limit & Offset

```php
// select * from books.json offset 4
$bookCollection->skip(4)->get();

// select * from books.json limit 10 offset 4
$bookCollection->take(10, 4)->get();
```

### Join

```php
$userCollection = new Collection('db/users');
$bookCollection = new Collection('db/books');

// get user with 'books'
$userCollection->withMany($bookCollection, 'books', 'author.email', '=', 'email')->get();

// get books with 'user'
$bookCollection->withOne($userCollection, 'user', 'email', '=', 'author.email')->get();
```

### Map & Save

```php
$bookCollection->where('star', '>', 3)->map(function($row) {
    $row['star'] = $row['star'] += 2;
    return $row;
})->save();
```

### Transaction

```php
$bookCollection->begin();

try {

    // insert, update, delete, etc 
    // will stored into variable (memory)

    $bookCollection->commit(); // until this

} catch(Exception $e) {

    $bookCollection->rollback();

}

// alternative to above

try {

    $bookCollection->transaction(function(Collection $db) {
        // insert, update, delete, etc
    });

} catch(Exception $e) {

    // catch the exception

}
```

### Macro Query

The query macro allows us to add a new method to the `Qubus\NoSql\Collection` instance so that we can use it repeatedly in a more fluid manner.

For example, we want to retrieve active user data, if in the usual way we can do a query like this:

```php
$users->where('active', 1)->get();
```

If used repeatedly, sometimes we forget to recognize the active user whose `active` value is `1`, or `true`, or `yes`, or `YES`, or `Yes`, or `y`, or `Y`, etc.?

So to make things easier, we can use the following macros:

```php
$users->macro('active', function ($query) {
    return $query->where('active', 1);
});
```

So that we can get active users in this way:

```php
$users->active()->get();
```

## License
Released under the MIT [License](https://opensource.org/licenses/MIT).