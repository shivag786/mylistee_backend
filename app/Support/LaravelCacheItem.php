<?php

namespace App\Support;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/** One entry of {@see LaravelCachePool}. */
final class LaravelCacheItem implements CacheItemInterface
{
    private ?int $expiresAt = null;

    public function __construct(
        private readonly string $key,
        private mixed $value,
        private bool $hit,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiresAt = match (true) {
            $time === null => null,
            $time instanceof DateInterval => (new \DateTimeImmutable())->add($time)->getTimestamp(),
            default => time() + $time,
        };

        return $this;
    }

    /** Null means no expiry. */
    public function secondsToLive(): ?int
    {
        return $this->expiresAt === null ? null : $this->expiresAt - time();
    }
}
