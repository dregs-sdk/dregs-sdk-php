<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Arguments;
use Dregs\Internal\Lister;
use Dregs\Internal\Payload;
use Dregs\Model\Event;
use Dregs\Model\Page;
use Generator;
use InvalidArgumentException;

/**
 * The `$client->events` namespace: the events Dregs has recorded.
 *
 * To record one, use {@see Client::track()}.
 */
final class Events
{
    /**
     * @param Client $client The client whose credentials and base URL these calls use.
     *
     * @internal Built by {@see Client}. Reach it as `$client->events`.
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Returns one page of events, newest first.
     *
     * @param string|null $identity  Only events from this identity.
     * @param string|null $group     Only events sent with the group with this id.
     * @param string|null $groupType Only events sent with a group of this type.
     * @param string|null $device    Only events from the device with this fingerprint.
     * @param string|null $session   Only events from this browser session.
     * @param string|null $term      Free text matched against event names and data.
     * @param int         $page      The page to return, counting from 0.
     * @param int|null    $pageSize  Items per page, at most 100.
     * @param string|null $sort      The field to sort on, such as "timestamp" or "eventName".
     * @param string|null $order     "asc" or "desc".
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException A negative page, or an order other than "asc" or
     *                                  "desc".
     *
     * @return Page<Event>
     */
    public function list(
        ?string $identity = null,
        ?string $group = null,
        ?string $groupType = null,
        ?string $device = null,
        ?string $session = null,
        ?string $term = null,
        int $page = 0,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Page {
        return $this->lister($identity, $group, $groupType, $device, $session, $term, $pageSize, $sort, $order)
            ->page($page);
    }

    /**
     * Yields every event that matches, fetching pages as it goes. See {@see self::list()}.
     *
     * ```php
     * foreach ($client->events->listAll(identity: 'user_12345') as $event) {
     *     echo $event->timestamp?->format('c'), ' ', $event->name, PHP_EOL;
     * }
     * ```
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An order other than "asc" or "desc".
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function listAll(
        ?string $identity = null,
        ?string $group = null,
        ?string $groupType = null,
        ?string $device = null,
        ?string $session = null,
        ?string $term = null,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Generator {
        return $this->lister($identity, $group, $groupType, $device, $session, $term, $pageSize, $sort, $order)
            ->walk();
    }

    /**
     * Returns the event with this id.
     *
     * @param string $eventId The event's id, either your own or one Dregs generated.
     *
     * @throws NotFoundException        No event has this id.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function get(string $eventId): Event
    {
        $payload = $this->client->request('GET', '/events/' . Arguments::segment($eventId, 'An event id'));

        return Event::fromApi(Payload::asMap($payload));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return Lister<Event>
     */
    private function lister(
        ?string $identity,
        ?string $group,
        ?string $groupType,
        ?string $device,
        ?string $session,
        ?string $term,
        ?int $pageSize,
        ?string $sort,
        ?string $order,
    ): Lister {
        $filters = [
            'identity' => $identity,
            'group' => $group,
            'groupType' => $groupType,
            'device' => $device,
            'session' => $session,
            'term' => $term,
        ];

        return new Lister($this->client, '/events', Event::fromApi(...), $filters, $pageSize, $sort, $order);
    }
}
