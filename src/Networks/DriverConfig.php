<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Networks;

use Vimatech\EInvoicing\Exceptions\InvalidDriverConfig;

/**
 * Typed, read-only access to a network driver's untyped configuration array.
 *
 * An absent or null key takes the caller's default; a key that is present but
 * unreadable as the requested type raises InvalidDriverConfig. A value someone
 * set is never silently replaced by a default. Environment variables arrive as
 * strings, so numeric and boolean strings are accepted and anything else is not.
 */
final readonly class DriverConfig
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private string $network, private array $values) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        throw InvalidDriverConfig::expected($this->network, $key, 'a string', $value);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw InvalidDriverConfig::expected($this->network, $key, 'an integer or a whole-number string', $value);
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $literal = strtolower(trim($value));

            if ($literal === 'true' || $literal === '1') {
                return true;
            }

            if ($literal === 'false' || $literal === '0') {
                return false;
            }
        }

        throw InvalidDriverConfig::expected($this->network, $key, 'a boolean or one of the strings "true", "false", "1", "0"', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        throw InvalidDriverConfig::expected($this->network, $key, 'an array', $value);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $values = [];

        foreach ($this->array($key) as $index => $value) {
            if (! is_string($value)) {
                throw InvalidDriverConfig::expected($this->network, $key.'.'.$index, 'a string', $value);
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function map(string $key): array
    {
        $map = [];

        foreach ($this->array($key) as $name => $value) {
            if (! is_string($value)) {
                throw InvalidDriverConfig::expected($this->network, $key.'.'.$name, 'a string', $value);
            }

            $map[(string) $name] = $value;
        }

        return $map;
    }

    public function path(string $name, string $default): string
    {
        $value = $this->array('paths')[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        throw InvalidDriverConfig::expected($this->network, 'paths.'.$name, 'a string', $value);
    }
}
