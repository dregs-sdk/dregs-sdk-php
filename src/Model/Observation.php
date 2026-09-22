<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * One analyzer's finding, and the reasoning behind a slice of a score.
 *
 * Observations are the evidence. When you need to show a reviewer, or log for an audit, why
 * an identity scored the way it did, these are what you show them — the score alone is a
 * summary with the reasoning thrown away.
 */
final readonly class Observation
{
    /**
     * @param Category|null        $category    The category this contributes to, or null when
     *                                          the API named one this release predates.
     * @param string|null          $id          The analyzer's identifier, such as
     *                                          "humanity.user-agent".
     * @param string|null          $label       A human-readable name for the analyzer.
     * @param string|null          $explanation A sentence describing what the analyzer found.
     *                                          This is written for a person to read, and is
     *                                          the field to surface in a review queue.
     * @param float|null           $value       0.0 for entirely suspicious, 1.0 for entirely
     *                                          legitimate.
     * @param float|null           $confidence  How sure the analyzer is, from 0.0 to 1.0. A
     *                                          damning value at low confidence usually means
     *                                          there was not much evidence to go on yet.
     * @param float|null           $weight      How heavily this counts toward the category
     *                                          score, relative to the other observations in
     *                                          it.
     * @param array<string, mixed> $metadata    The counts and details behind the finding.
     * @param array<string, mixed> $raw         The observation's body as received.
     */
    public function __construct(
        public ?Category $category,
        public ?string $id,
        public ?string $label,
        public ?string $explanation,
        public ?float $value,
        public ?float $confidence,
        public ?float $weight,
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
            Category::tryFromApi($payload['category'] ?? null),
            Payload::string($payload, 'id'),
            Payload::string($payload, 'label'),
            Payload::string($payload, 'explanation'),
            Payload::float($payload, 'value'),
            Payload::float($payload, 'confidence'),
            Payload::float($payload, 'weight'),
            Payload::map($payload, 'metadata'),
            $payload,
        );
    }
}
