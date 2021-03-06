<?php

declare(strict_types=1);

namespace Yiisoft\Cache\Apcu;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;

/**
 * ApcuCache provides APCu caching in terms of an application component.
 *
 * To use this application component, the [APCu PHP extension](http://www.php.net/apcu) must be loaded.
 * In order to enable APCu for CLI you should add "apc.enable_cli = 1" to your php.ini.
 *
 * See {@see \Psr\SimpleCache\CacheInterface} for common cache operations that ApcCache supports.
 */
class ApcuCache implements CacheInterface
{
    private const TTL_INFINITY = 0;

    public function get($key, $default = null)
    {
        $this->validateKey($key);
        $value = \apcu_fetch($key, $success);
        return $success ? $value : $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);
        $ttl = $this->normalizeTtl($ttl);
        if ($ttl < 0) {
            return $this->delete($key);
        }
        return \apcu_store($key, $value, $ttl);
    }

    public function delete($key): bool
    {
        $this->validateKey($key);
        return \apcu_delete($key);
    }

    public function clear(): bool
    {
        return \apcu_clear_cache();
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);
        $valuesFromCache = \apcu_fetch($keys, $success) ?: [];
        $valuesFromCache = $this->normalizeAPCuOutput($valuesFromCache);
        $values = array_fill_keys($keys, $default);
        foreach ($values as $key => $value) {
            $values[$key] = $valuesFromCache[(string)$key] ?? $value;
        }

        return $values;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        $values = $this->iterableToArray($values);
        $this->validateKeysOfValues($values);
        [$valuesWithStringKeys, $valuesWithIntegerKeys] = $this->splitValuesByKeyType($values);
        $ttl = $this->normalizeTtl($ttl);
        $result = \apcu_store($valuesWithStringKeys, null, $ttl) === [];
        foreach ($valuesWithIntegerKeys as $key => $value) {
            $result = $result && \apcu_store((string)$key, $value, $ttl);
        }

        return $result;
    }

    public function deleteMultiple($keys): bool
    {
        $keys = $this->iterableToArray($keys);
        $this->validateKeys($keys);
        return \apcu_delete($keys) === [];
    }

    public function has($key): bool
    {
        $this->validateKey($key);
        return \apcu_exists($key);
    }

    /**
     * @noinspection PhpDocMissingThrowsInspection DateTime won't throw exception because constant string is passed as time
     *
     * Normalizes cache TTL handling `null` value, strings and {@see DateInterval} objects.
     * @param int|string|DateInterval|null $ttl raw TTL.
     * @return int TTL value as UNIX timestamp
     */
    private function normalizeTtl($ttl): ?int
    {
        $normalizedTtl = $ttl;
        if ($ttl instanceof DateInterval) {
            $normalizedTtl = (new DateTime('@0'))->add($ttl)->getTimestamp();
        }

        if (is_string($normalizedTtl)) {
            $normalizedTtl = (int)$normalizedTtl;
        }

        return $normalizedTtl ?? static::TTL_INFINITY;
    }

    /**
     * Converts iterable to array. If provided value is not iterable it throws an InvalidArgumentException
     * @param mixed $iterable
     * @return array
     */
    private function iterableToArray($iterable): array
    {
        if (!is_iterable($iterable)) {
            throw new InvalidArgumentException('Iterable is expected, got ' . gettype($iterable));
        }

        return $iterable instanceof \Traversable ? iterator_to_array($iterable) : (array)$iterable;
    }

    /**
     * @param mixed $key
     */
    private function validateKey($key): void
    {
        if (!\is_string($key)) {
            throw new InvalidArgumentException('Invalid key value.');
        }
    }

    /**
     * @param array $keys
     */
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    /**
     * @param array $values
     */
    private function validateKeysOfValues(array $values): void
    {
        $keys = array_map('strval', array_keys($values));
        $this->validateKeys($keys);
    }

    /**
     * Normalizes keys returned from apcu_fetch in multiple mode. If one of the keys is an integer (123) or a string
     * representation of an integer ('123') the returned key from the cache doesn't equal neither to an integer nor a
     * string ($key !== 123 and $key !== '123'). Coping element from the returned array one by one to the new array
     * fixes this issue.
     * @param array $values
     * @return array
     */
    private function normalizeAPCuOutput(array $values): array
    {
        $normalizedValues = [];
        foreach ($values as $key => $value) {
            $normalizedValues[$key] = $value;
        }

        return $normalizedValues;
    }

    /**
     * Splits the array of values into two arrays, one with int keys and one with string keys
     * @param array $values
     * @return array
     */
    private function splitValuesByKeyType(array $values): array
    {
        $withIntKeys = [];
        foreach ($values as $key => $value) {
            if (\is_int($key)) {
                $withIntKeys[$key] = $value;
                unset($values[$key]);
            }
        }

        return [$values, $withIntKeys];
    }
}
