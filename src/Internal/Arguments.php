<?php

declare(strict_types=1);

namespace Dregs\Internal;

use InvalidArgumentException;

/**
 * Checks and shapes the arguments the namespaces pass on, so a mistake is refused before any
 * request is made rather than coming back from the API as a 400 or, worse, a 404 for the
 * wrong resource.
 *
 * @internal This class is not part of the SDK's public surface and may change in a patch
 *           release.
 */
final class Arguments
{
    private function __construct()
    {
    }

    /**
     * Escapes one path segment, refusing an empty one.
     *
     * Ids are the caller's own and routinely contain characters that need escaping, an email
     * address being the common one, and a slash in an id must not be read as a path separator.
     *
     * @param string|int $value The id.
     * @param string     $what  How the error names it, such as "An identity id".
     *
     * @throws InvalidArgumentException
     */
    public static function segment(string|int $value, string $what): string
    {
        return rawurlencode(self::required((string) $value, $what));
    }

    /**
     * Returns the value, refusing an empty one.
     *
     * @param string $value The id.
     * @param string $what  How the error names it, such as "A group id".
     *
     * @throws InvalidArgumentException
     */
    public static function required(string $value, string $what): string
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$what} is required.");
        }

        return $value;
    }

    /**
     * Shapes attributes for a request body, so that an empty array is sent as `{}` rather than
     * the `[]` json_encode would otherwise make of it.
     *
     * @param array<mixed> $attributes
     *
     * @throws InvalidArgumentException The array is a non-empty list rather than keyed by name.
     */
    public static function attributes(array $attributes): object
    {
        if ($attributes !== [] && array_is_list($attributes)) {
            throw new InvalidArgumentException(
                "attributes must be keyed by name, such as ['name' => 'Acme Inc']."
            );
        }

        return (object) $attributes;
    }
}
