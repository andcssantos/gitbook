<?php

namespace Tests\Database;

use App\Database\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    public function testBuildsSelectSql(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $query = (new QueryBuilder($pdo, 'players'))
            ->select(['id', 'name'])
            ->where('id', '=', 10)
            ->orderBy('id', 'DESC')
            ->limit(1);

        $this->assertSame('SELECT id, name FROM players WHERE id = :p0 ORDER BY id DESC LIMIT 1', $query->toSql());
    }

    public function testBuildsSelectForUpdateSql(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $query = (new QueryBuilder($pdo, 'market_listings'))
            ->where('id', '=', 10)
            ->forUpdate();

        $this->assertSame('SELECT * FROM market_listings WHERE id = :p0 FOR UPDATE', $query->toSql());
    }
}
