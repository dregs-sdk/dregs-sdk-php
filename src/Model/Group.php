<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * An organization, team, workspace, or whatever your application groups users into.
 *
 * A group is identified by its `$type` and `$id` together, both your own. Dregs normalizes the
 * type to lower_snake_case.
 *
 * Like an identity's, a group's attributes were written by your users. Escape them before
 * rendering them.
 */
final readonly class Group
{
    /**
     * @param string|null            $type          The kind of group, such as "organization"
     *                                              or "team".
     * @param string|null            $id            Your own id for the group.
     * @param string|null            $displayName   A name for display, taken from the group's
     *                                              attributes.
     * @param int|null               $identityCount How many identities are members.
     * @param DateTimeImmutable|null $createdAt     When Dregs first recorded the group.
     * @param DateTimeImmutable|null $updatedAt     When it last changed.
     * @param DateTimeImmutable|null $lastTrackedAt When an event last arrived with it.
     * @param array<string, mixed>   $data          The group's attributes. Empty on the groups
     *                                              of a list call, which leaves them out;
     *                                              `groups->get()` returns them.
     * @param array<string, mixed>   $raw           The response body as received.
     */
    public function __construct(
        public ?string $type,
        public ?string $id,
        public ?string $displayName = null,
        public ?int $identityCount = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $lastTrackedAt = null,
        public array $data = [],
        public array $raw = [],
    ) {
    }

    /**
     * Builds a group from a decoded `GET /api/groups/{type}/{id}` response, or from one item
     * of a list.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'type'),
            Payload::id($payload, 'id'),
            Payload::string($payload, 'displayName'),
            Payload::int($payload, 'identityCount'),
            Dates::parse($payload['createdAt'] ?? null),
            Dates::parse($payload['updatedAt'] ?? null),
            Dates::parse($payload['lastTrackedAt'] ?? null),
            Payload::map($payload, 'data'),
            $payload,
        );
    }
}
