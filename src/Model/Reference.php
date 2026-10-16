<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * A pointer to another resource: its id, a label for display, and its API path.
 *
 * An event points at its identity this way, and an escalation at its identity and its rule.
 */
final readonly class Reference
{
    /**
     * @param string|null          $id    The resource's id. A numeric id, such as an
     *                                    escalation rule's, is read as a string.
     * @param string|null          $label A name for display.
     * @param string|null          $path  The resource's path in the API.
     * @param array<string, mixed> $raw   The reference's body as received.
     */
    public function __construct(
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
            Payload::id($payload, 'id'),
            Payload::string($payload, 'label'),
            Payload::string($payload, 'path'),
            $payload,
        );
    }
}
