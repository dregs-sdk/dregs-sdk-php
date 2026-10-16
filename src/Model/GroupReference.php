<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * A pointer to a group, as it appears on an event.
 *
 * A group is identified by its type and id together, so a reference carries both.
 */
final readonly class GroupReference
{
    /**
     * @param string|null          $type  The kind of group, such as "organization" or "team".
     * @param string|null          $id    Your own id for the group.
     * @param string|null          $label A name for display.
     * @param string|null          $path  The group's path in the API.
     * @param array<string, mixed> $raw   The reference's body as received.
     */
    public function __construct(
        public ?string $type,
        public ?string $id,
        public ?string $label = null,
        public ?string $path = null,
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'type'),
            Payload::id($payload, 'id'),
            Payload::string($payload, 'label'),
            Payload::string($payload, 'path'),
            $payload,
        );
    }
}
