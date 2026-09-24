<?php

declare(strict_types=1);

namespace Dregs\Internal;

/**
 * Reads configuration out of the environment.
 *
 * `getenv()` alone misses variables that were injected into `$_ENV` or `$_SERVER` without
 * reaching the process environment, which is how several PHP-FPM and Docker setups pass them,
 * so all three are consulted.
 *
 * @internal This class is not part of the SDK's public surface and may change in a patch
 *           release.
 */
final class Env
{
    private function __construct()
    {
    }

    /**
     * Returns the variable's value, or null when it is unset or empty.
     */
    public static function get(string $name): ?string
    {
        $value = getenv($name);

        if (!is_string($value) || $value === '') {
            $value = self::fromSuperglobal($_ENV, $name) ?? self::fromSuperglobal($_SERVER, $name);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $source A superglobal, which PHP types loosely enough
     *                                        that the key type cannot be assumed.
     */
    private static function fromSuperglobal(array $source, string $name): ?string
    {
        $value = $source[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
