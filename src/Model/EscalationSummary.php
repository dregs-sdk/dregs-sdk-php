<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * How many escalations are in each status.
 */
final readonly class EscalationSummary
{
    /**
     * @param int                  $open         How many are open.
     * @param int                  $acknowledged How many are acknowledged but not closed.
     * @param int                  $closed       How many are closed.
     * @param array<string, mixed> $raw          The response body as received.
     */
    public function __construct(
        public int $open = 0,
        public int $acknowledged = 0,
        public int $closed = 0,
        public array $raw = [],
    ) {
    }

    /**
     * Builds a summary from a decoded `GET /api/escalations/summary` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::int($payload, 'open') ?? 0,
            Payload::int($payload, 'acknowledged') ?? 0,
            Payload::int($payload, 'closed') ?? 0,
            $payload,
        );
    }
}
