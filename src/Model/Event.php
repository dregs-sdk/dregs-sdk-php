<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * An event Dregs recorded, from the browser tracker or from a `track()` call.
 *
 * The event's data was written by your users, or on their behalf. Escape it before rendering
 * it.
 */
final readonly class Event
{
    /**
     * @param string|null            $id            The event's id, either the one you sent or
     *                                              one Dregs generated.
     * @param DateTimeImmutable|null $timestamp     When the event happened.
     * @param Reference|null         $identity      The identity the event belongs to, if any.
     * @param list<GroupReference>   $groups        The groups the event was sent with.
     * @param string|null            $fingerprint   The device fingerprint, for events that
     *                                              carried a device signature.
     * @param string|null            $session       The browser session, for events from the
     *                                              tracker.
     * @param string|null            $ip            The address the event came from.
     * @param string|null            $url           The page URL, for events from the tracker.
     * @param string|null            $source        Where the event came from, such as
     *                                              "php-sdk".
     * @param string|null            $name          Your name for the event, such as
     *                                              "user.signup".
     * @param string|null            $canonicalType The Dregs event type your name is mapped
     *                                              to, if it is mapped.
     * @param array<string, mixed>   $data          The event's attributes, with sensitive
     *                                              values redacted.
     * @param array<string, mixed>   $raw           The response body as received.
     */
    public function __construct(
        public ?string $id,
        public ?DateTimeImmutable $timestamp = null,
        public ?Reference $identity = null,
        public array $groups = [],
        public ?string $fingerprint = null,
        public ?string $session = null,
        public ?string $ip = null,
        public ?string $url = null,
        public ?string $source = null,
        public ?string $name = null,
        public ?string $canonicalType = null,
        public array $data = [],
        public array $raw = [],
    ) {
    }

    /**
     * Builds an event from a decoded `GET /api/events/{id}` response, or from one item of a
     * list.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        $identity = $payload['identity'] ?? null;
        $groups = [];

        foreach (Payload::objects($payload, 'groups') as $group) {
            $groups[] = GroupReference::fromApi($group);
        }

        return new self(
            Payload::id($payload, 'id'),
            Dates::parse($payload['timestamp'] ?? null),
            is_array($identity) ? Reference::fromApi(Payload::asMap($identity)) : null,
            $groups,
            Payload::string($payload, 'fingerprint'),
            Payload::string($payload, 'session'),
            Payload::string($payload, 'ip'),
            Payload::string($payload, 'url'),
            Payload::string($payload, 'eventSource'),
            Payload::string($payload, 'eventName'),
            Payload::string($payload, 'canonicalType'),
            Payload::map($payload, 'eventData'),
            $payload,
        );
    }
}
