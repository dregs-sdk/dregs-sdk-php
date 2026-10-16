<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * The scores one past analysis cycle produced.
 *
 * Pass `$cycleId` to `identities->analysis()` to fetch that cycle's observations.
 */
final readonly class ScoreHistoryEntry
{
    /**
     * @param int|null               $cycleId      The cycle's id.
     * @param int|null               $humanity     0 to 100, or null when unscored.
     * @param int|null               $authenticity 0 to 100, or null when unscored.
     * @param int|null               $uniqueness   0 to 100, or null when unscored.
     * @param int|null               $behavior     0 to 100, or null when unscored.
     * @param int|null               $eventCount   How many events the cycle considered.
     * @param int|null               $deviceCount  How many devices the cycle considered.
     * @param DateTimeImmutable|null $finishedAt   When the cycle finished.
     * @param array<string, mixed>   $raw          The entry's body as received.
     */
    public function __construct(
        public ?int $cycleId,
        public ?int $humanity = null,
        public ?int $authenticity = null,
        public ?int $uniqueness = null,
        public ?int $behavior = null,
        public ?int $eventCount = null,
        public ?int $deviceCount = null,
        public ?DateTimeImmutable $finishedAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Builds an entry from one item of a decoded `GET /api/identities/{id}/history` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::int($payload, 'id'),
            Payload::int($payload, 'humanityScore'),
            Payload::int($payload, 'authenticityScore'),
            Payload::int($payload, 'uniquenessScore'),
            Payload::int($payload, 'behaviorScore'),
            Payload::int($payload, 'eventCount'),
            Payload::int($payload, 'deviceCount'),
            Dates::parse($payload['finishedAt'] ?? null),
            $payload,
        );
    }
}
