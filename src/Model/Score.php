<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * One category's score.
 */
final readonly class Score
{
    /**
     * @param Category|null        $category     The category scored, or null when the API
     *                                           named one this release predates.
     * @param int|null             $value        An integer from 0 (worst) to 100 (best).
     * @param list<Observation>    $observations The observations behind the score. This is
     *                                           empty on the result of
     *                                           {@see \Dregs\Identities::scores()}, which
     *                                           reports the scores alone; the observations
     *                                           come from {@see \Dregs\Identities::analysis()}.
     * @param array<string, mixed> $raw          The score's body as received.
     */
    public function __construct(
        public ?Category $category,
        public ?int $value,
        public array $observations = [],
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        $observations = [];

        foreach (Payload::objects($payload, 'observations') as $observation) {
            $observations[] = Observation::fromApi($observation);
        }

        return new self(
            Category::tryFromApi($payload['category'] ?? null),
            Payload::int($payload, 'value'),
            $observations,
            $payload,
        );
    }
}
