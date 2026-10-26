<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A PSR-6 cache pool over a Laravel cache store, for libraries that want one
 * (the Firebase SDK keeps Google's token-signing keys in it). Without it the
 * SDK's in-memory cache dies with each request, so every Google sign-in
 * fetched the keys from Google again.
 */
class LaravelCachePool implements CacheItemPoolInterface
{
    /** @var array<string, LaravelCacheItem> */
    private array $deferred = [];

    public function __construct(
        private readonly Repository $store,
        private readonly string $prefix = 'psr6:',
    ) {}

    public function getItem(string $key): CacheItemInterface
    {
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        $stored = $this->store->get($this->prefix.$key);

        return new LaravelCacheItem($key, $stored['value'] ?? null, is_array($stored));
    }

    /** @return iterable<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        // Only this pool's deferred items: the shared store holds other data.
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->deferred[$key]);

        return $this->store->forget($this->prefix.$key);
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (! $item instanceof LaravelCacheItem) {
            return false;
        }

        // Wrapped, so a cached `null` or `false` still reads as a hit.
        $value = ['value' => $item->get()];
        $seconds = $item->secondsToLive();

        if ($seconds === null) {
            return $this->store->forever($this->prefix.$item->getKey(), $value);
        }
        if ($seconds <= 0) {
            return $this->deleteItem($item->getKey());
        }

        return $this->store->put($this->prefix.$item->getKey(), $value, $seconds);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (! $item instanceof LaravelCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }
        $this->deferred = [];

        return $ok;
    }
}
