<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * One analysis cycle: the scores an identity was given, and why.
 *
 * A cycle is a snapshot. Dregs runs a new one as events arrive, so what you have here is what
 * the analyzers concluded at `$finishedAt` from the events they could see then.
 */
final readonly class Analysis
{
    /**
     * @param int|null               $id             The cycle's identifier.
     * @param string|null            $identityId     The identity that was analyzed.
     * @param Scores                 $scores         The category scores, each carrying its
     *                                               observations.
     * @param int|null               $eventCount     How many events the cycle considered.
     *                                               Events outside your plan's retention
     *                                               window are not among them.
     * @param int|null               $deviceCount    How many devices the cycle considered.
     * @param int|null               $durationMillis How long the cycle took.
     * @param DateTimeImmutable|null $startedAt      When the cycle began.
     * @param DateTimeImmutable|null $finishedAt     When it finished, which is the moment
     *                                               these conclusions describe.
     * @param array<string, mixed>   $raw            The response body as received.
     */
    public function __construct(
        public ?int $id,
        public ?string $identityId,
        public Scores $scores = new Scores(),
        public ?int $eventCount = null,
        public ?int $deviceCount = null,
        public ?int $durationMillis = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Every observation from the cycle, across all four categories.
     *
     * Sort them by `value` ascending to put the most damning findings first.
     *
     * @return list<Observation>
     */
    public function observations(): array
    {
        $observations = [];

        foreach ($this->scores as $score) {
            foreach ($score->observations as $observation) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    /**
     * Builds a cycle from a decoded `GET /api/identities/{id}/analysis` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::int($payload, 'id'),
            Payload::string($payload, 'identityId'),
            Scores::fromApi(Payload::objects($payload, 'scores')),
            Payload::int($payload, 'eventCount'),
            Payload::int($payload, 'deviceCount'),
            Payload::int($payload, 'durationMillis'),
            Dates::parse($payload['startedAt'] ?? null),
            Dates::parse($payload['finishedAt'] ?? null),
            $payload,
        );
    }
}
