<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * A label Dregs applied to an identity, from an analyzer or from a badge rule.
 *
 * Badges are the coarse, actionable form of what the scores say in numbers: "Account Takeover
 * Suspected" is easier to branch on than a behavior score of 12. An analyzer-sourced badge is
 * removed again once the observation behind it stops firing, so a badge reflects what Dregs
 * thinks now rather than what it once thought.
 */
final readonly class Badge
{
    /**
     * @param string|null          $slug        The badge's stable identifier.
     * @param string|null          $name        The human-readable name shown in the
     *                                          dashboard.
     * @param string|null          $type        How the badge should be read, such as
     *                                          "WARNING" or "INFO".
     * @param string|null          $explanation A sentence describing why it was applied.
     * @param array<string, mixed> $metadata    The counts and details behind it.
     * @param array<string, mixed> $raw         The badge's body as received.
     */
    public function __construct(
        public ?string $slug,
        public ?string $name,
        public ?string $type,
        public ?string $explanation,
        public array $metadata = [],
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'slug'),
            Payload::string($payload, 'name'),
            Payload::string($payload, 'type'),
            Payload::string($payload, 'explanation'),
            Payload::map($payload, 'metadata'),
            $payload,
        );
    }
}
