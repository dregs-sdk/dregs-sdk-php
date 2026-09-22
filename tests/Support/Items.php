<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Reaching into a collection without losing the element's type.
 *
 * `$list[0]` on a list that static analysis cannot prove is non-empty is an error worth
 * keeping switched on, so the tests ask for the first element through here instead.
 */
final class Items
{
    private function __construct()
    {
    }

    /**
     * The first element, failing the test when the collection is empty.
     *
     * @template T
     *
     * @param iterable<T> $items
     *
     * @return T
     */
    public static function first(iterable $items): mixed
    {
        foreach ($items as $item) {
            return $item;
        }

        Assert::fail('Expected at least one item, and there were none.');
    }
}
