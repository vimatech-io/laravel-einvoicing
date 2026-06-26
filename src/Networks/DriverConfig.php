<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

/**
 * Typed, read-only access to a network driver's untyped configuration array.
 *
 * Keeps the (inherently mixed) config-parsing concern out of the drivers
 * themselves, so an HTTP driver only ever deals with already-narrowed values.
 */
final readonly class DriverConfig
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The string values of a config sub-array, discarding anything non-string.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $values = [];

        foreach ($this->array($key) as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * A string => string sub-map (e.g. headers, status_map).
     *
     * @return array<string, string>
     */
    public function map(string $key): array
    {
        $map = [];

        foreach ($this->array($key) as $name => $value) {
            if (is_string($value)) {
                $map[(string) $name] = $value;
            }
        }

        return $map;
    }

    /**
     * Resolve a named request path from the `paths` sub-map.
     */
    public function path(string $name, string $default): string
    {
        $value = $this->array('paths')[$name] ?? null;

        return is_string($value) ? $value : $default;
    }
}
