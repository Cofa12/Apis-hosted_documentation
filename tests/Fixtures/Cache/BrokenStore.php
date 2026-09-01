<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Cache;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store that cannot be reached — the database cache driver pointed at
 * a connection whose `cache` table was never migrated, a Redis that is down.
 *
 * Documentation must not depend on the application's cache being healthy.
 */
class BrokenStore implements Store
{
    public int $calls = 0;

    public function __construct(
        public string $message = 'SQLSTATE[HY000]: General error: 1 no such table: cache'
    ) {
    }

    protected function fail(): never
    {
        $this->calls++;

        throw new RuntimeException($this->message);
    }

    public function get($key)
    {
        $this->fail();
    }

    public function many(array $keys)
    {
        $this->fail();
    }

    public function put($key, $value, $seconds)
    {
        $this->fail();
    }

    public function putMany(array $values, $seconds)
    {
        $this->fail();
    }

    public function increment($key, $value = 1)
    {
        $this->fail();
    }

    public function decrement($key, $value = 1)
    {
        $this->fail();
    }

    public function forever($key, $value)
    {
        $this->fail();
    }

    public function forget($key)
    {
        $this->fail();
    }

    public function touch($key, $seconds)
    {
        $this->fail();
    }

    public function flush()
    {
        $this->fail();
    }

    public function getPrefix()
    {
        return '';
    }
}
