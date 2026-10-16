<?php

declare(strict_types=1);

namespace Dregs\Model;

use ArrayIterator;
use Closure;
use Countable;
use Dregs\Internal\Payload;
use IteratorAggregate;
use Traversable;

/**
 * One page of a list call.
 *
 * Iterate it and count it as the items on this page. Pages are numbered from 0. To walk every
 * page, use the namespace's `listAll()` rather than looping on `$pageNumber` yourself.
 *
 * ```php
 * $page = $client->identities->list(term: 'example.com', pageSize: 50);
 *
 * foreach ($page as $identity) {
 *     echo $identity->id, PHP_EOL;
 * }
 *
 * $page->totalElements;  // how many match in all
 * $page->hasNext();      // whether another page follows
 * ```
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page implements Countable, IteratorAggregate
{
    /**
     * @param list<T>              $items         The items on this page.
     * @param int                  $pageNumber    This page's number, counting from 0.
     * @param int                  $pageSize      The most items a page holds.
     * @param int                  $totalPages    How many pages there are in all.
     * @param int                  $totalElements How many items there are across every page.
     * @param bool                 $first         Whether this is the first page.
     * @param bool                 $last          Whether this is the last page.
     * @param array<string, mixed> $raw           The response body as received.
     */
    public function __construct(
        public array $items = [],
        public int $pageNumber = 0,
        public int $pageSize = 0,
        public int $totalPages = 0,
        public int $totalElements = 0,
        public bool $first = true,
        public bool $last = true,
        public array $raw = [],
    ) {
    }

    /**
     * Returns the items on this page, as a plain list.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * How many items are on this page, which is at most `$pageSize`.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Whether this page holds no items.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Whether another page follows this one.
     *
     * An empty page never has a next one, whatever it is marked, so a loop on this cannot run
     * forever against a collection that shrank while it was being walked.
     */
    public function hasNext(): bool
    {
        return !$this->last && $this->items !== [];
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Builds a page from a decoded `V1Page` response.
     *
     * @template U
     *
     * @param array<string, mixed>             $payload
     * @param Closure(array<string, mixed>): U $parse   Builds one item from its body.
     *
     * @return Page<U>
     */
    public static function fromApi(array $payload, Closure $parse): self
    {
        $items = [];

        foreach (Payload::objects($payload, 'content') as $item) {
            $items[] = $parse($item);
        }

        return new self(
            $items,
            Payload::int($payload, 'pageNumber') ?? 0,
            Payload::int($payload, 'pageSize') ?? 0,
            Payload::int($payload, 'totalPages') ?? 0,
            Payload::int($payload, 'totalElements') ?? 0,
            ($payload['first'] ?? true) !== false,
            ($payload['last'] ?? true) !== false,
            $payload,
        );
    }
}
