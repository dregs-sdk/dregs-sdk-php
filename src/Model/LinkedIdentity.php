<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * Another identity Dregs believes is connected to this one, such as by a shared device.
 */
final readonly class LinkedIdentity
{
    /**
     * @param string|null            $identityId  The linked identity's id.
     * @param string|null            $displayName The linked identity's display name.
     * @param string|null            $type        How they are linked, such as "SAME_DEVICE"
     *                                            or "SHARED_IP".
     * @param float|null             $value       How strongly they are linked, from 0.0 to
     *                                            1.0.
     * @param float|null             $confidence  How sure Dregs is of the link, from 0.0 to
     *                                            1.0.
     * @param string|null            $explanation A sentence describing the link.
     * @param array<string, mixed>   $metadata    The details behind it.
     * @param DateTimeImmutable|null $createdAt   When Dregs found the link.
     * @param array<string, mixed>   $raw         The link's body as received.
     */
    public function __construct(
        public ?string $identityId,
        public ?string $displayName = null,
        public ?string $type = null,
        public ?float $value = null,
        public ?float $confidence = null,
        public ?string $explanation = null,
        public array $metadata = [],
        public ?DateTimeImmutable $createdAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Builds a link from one item of a decoded `GET /api/identities/{id}/links` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'targetIdentityId'),
            Payload::string($payload, 'targetDisplayName'),
            Payload::string($payload, 'type'),
            Payload::float($payload, 'value'),
            Payload::float($payload, 'confidence'),
            Payload::string($payload, 'explanation'),
            Payload::map($payload, 'metadata'),
            Dates::parse($payload['createdAt'] ?? null),
            $payload,
        );
    }
}
