<?php

declare(strict_types=1);

namespace Dregs\Internal;

use Closure;
use Dregs\Client;
use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Model\Page;
use Generator;
use InvalidArgumentException;

/**
 * One list call, described once and run either a page at a time or across every page.
 *
 * Every `list()` and `listAll()` in the SDK builds one of these. The filters, the page size,
 * the sort, and the order are checked when it is built, so a bad argument is refused before
 * any request even when the caller has only asked for a generator it has not started yet.
 *
 * @template T
 *
 * @internal This class is not part of the SDK's public surface and may change in a patch
 *           release.
 */
final class Lister
{
    /** @var array<string, string|int> */
    private readonly array $query;

    /**
     * @param Client                           $client   The client to send through.
     * @param string                           $path     The collection's path, such as
     *                                                   "/groups".
     * @param Closure(array<string, mixed>): T $parse    Builds one item from its body.
     * @param array<string, string|int|null>   $filters  The API's own filter parameters, by
     *                                                   name. Null ones are left out.
     * @param int|null                         $pageSize Items per page.
     * @param string|null                      $sort     The field to sort on.
     * @param string|null                      $order    "asc" or "desc", in either case.
     *
     * @throws InvalidArgumentException The order was neither "asc" nor "desc".
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $path,
        private readonly Closure $parse,
        array $filters,
        ?int $pageSize,
        ?string $sort,
        ?string $order,
    ) {
        $query = [];

        foreach ($filters as $name => $value) {
            if ($value !== null) {
                $query[$name] = $value;
            }
        }

        if ($pageSize !== null) {
            $query['pageSize'] = $pageSize;
        }

        if ($sort !== null) {
            $query['sort'] = $sort;
        }

        if ($order !== null) {
            $query['order'] = self::order($order);
        }

        $this->query = $query;
    }

    /**
     * Fetches one page.
     *
     * @param int $number The page, counting from 0.
     *
     * @throws InvalidArgumentException The page number was negative.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     *
     * @return Page<T>
     */
    public function page(int $number): Page
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Pages are numbered from 0.');
        }

        $payload = $this->client->request('GET', $this->path, null, $this->query + ['pageNumber' => $number]);

        return Page::fromApi(Payload::asMap($payload), $this->parse);
    }

    /**
     * Yields every item, fetching each page as the one before it runs out.
     *
     * Stops after a page marked last, and after an empty page whatever it is marked, so a
     * collection that shrinks while it is being walked cannot loop forever.
     *
     * @throws ApiException        Dregs answered with an error status.
     * @throws ConnectionException Dregs could not be reached.
     *
     * @return Generator<int, T, mixed, void>
     */
    public function walk(): Generator
    {
        $number = 0;

        while (true) {
            $page = $this->page($number);

            foreach ($page as $item) {
                yield $item;
            }

            if (!$page->hasNext()) {
                return;
            }

            ++$number;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function order(string $order): string
    {
        $lowered = strtolower($order);

        if ($lowered !== 'asc' && $lowered !== 'desc') {
            throw new InvalidArgumentException("order is 'asc' or 'desc'.");
        }

        return strtoupper($lowered);
    }
}
