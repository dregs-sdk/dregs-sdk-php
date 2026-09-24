<?php

declare(strict_types=1);

namespace Dregs\Internal;

/**
 * Lenient readers for the decoded body of an API response.
 *
 * Every model is built through these. A field that is missing, null, or of a type this
 * release did not expect reads as null rather than throwing, because an SDK that refuses to
 * parse a response it half-understands ages badly: the field it does not recognize is
 * usually one it did not need.
 *
 * @internal This class is not part of the SDK's public surface and may change in a patch
 *           release.
 */
final class Payload
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Reads an integer, accepting the float or numeric string a JSON encoder might have sent.
     *
     * @param array<string, mixed> $payload
     */
    public static function int(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (int) round($value);
        }

        return is_string($value) && is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function float(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Reads a flag, treating anything unrecognized as false.
     *
     * @param array<string, mixed> $payload
     */
    public static function bool(array $payload, string $key): bool
    {
        return ($payload[$key] ?? null) === true;
    }

    /**
     * Reads a nested object, such as an identity's `data` or an observation's `metadata`.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> The object's fields, or an empty array when the field was
     *                              absent or was not an object.
     */
    public static function map(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * Reads a list of nested objects, such as an identity's `badges`.
     *
     * Entries that are not objects are dropped rather than parsed into empty models.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? self::asObjects($value) : [];
    }

    /**
     * Reads a decoded response that is itself a list of objects, such as the scores endpoint.
     *
     * @param mixed $value
     *
     * @return list<array<string, mixed>>
     */
    public static function asObjects(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $objects = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $objects[] = self::asMap($item);
            }
        }

        return $objects;
    }

    /**
     * Normalises a decoded response into a string-keyed map.
     *
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    public static function asMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }
}
