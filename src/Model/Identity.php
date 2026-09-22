<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * A user Dregs is tracking, and their current scores.
 *
 * `$id` is your own identifier for the user — the one you pass to {@see \Dregs\Client::track()}
 * and to `dregs.identify()` in the browser tracker — not an internal Dregs id.
 *
 * The display fields and `$data` were written by the person being scored. Escape them before
 * rendering, and do not hand them to an agent as instructions: by construction, the people
 * writing that data are the ones trying to get something past you.
 */
final readonly class Identity
{
    /**
     * @param string|null            $id                Your own id for the user.
     * @param string|null            $displayName       The name Dregs resolved from the
     *                                                  attributes you sent, through your
     *                                                  field mappings.
     * @param string|null            $displayEmail      The resolved email address.
     * @param string|null            $displayUsername   The resolved username.
     * @param int|null               $humanityScore     0 to 100, or null when unscored.
     * @param int|null               $authenticityScore 0 to 100, or null when unscored.
     * @param int|null               $uniquenessScore   0 to 100, or null when unscored.
     * @param int|null               $behaviorScore     0 to 100, or null when unscored.
     * @param DateTimeImmutable|null $createdAt         When Dregs first recorded the identity.
     * @param DateTimeImmutable|null $updatedAt         When it last changed.
     * @param DateTimeImmutable|null $lastTrackedAt     When the most recent event arrived.
     * @param DateTimeImmutable|null $lastScoredAt      When scoring last ran. Compare it with
     *                                                  `$lastTrackedAt` to tell a stale score
     *                                                  from a current one.
     * @param bool                   $disregarded       Whether the identity is excluded from
     *                                                  fraud analysis, which is how operator
     *                                                  and load-test accounts are kept from
     *                                                  polluting everyone else's scores.
     * @param list<Badge>            $badges            The badges currently applied.
     * @param array<string, mixed>   $data              Every attribute you have sent for this
     *                                                  user.
     * @param array<string, mixed>   $raw               The response body as received.
     */
    public function __construct(
        public ?string $id,
        public ?string $displayName = null,
        public ?string $displayEmail = null,
        public ?string $displayUsername = null,
        public ?int $humanityScore = null,
        public ?int $authenticityScore = null,
        public ?int $uniquenessScore = null,
        public ?int $behaviorScore = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $lastTrackedAt = null,
        public ?DateTimeImmutable $lastScoredAt = null,
        public bool $disregarded = false,
        public array $badges = [],
        public array $data = [],
        public array $raw = [],
    ) {
    }

    /**
     * The identity's scores, as a {@see Scores} for parity with `identities->scores()`.
     *
     * The same four numbers the identity already carries, arranged so that code written
     * against one call works unchanged against the other. Categories that have not been
     * scored are left out.
     */
    public function scores(): Scores
    {
        $pairs = [
            [Category::Humanity, $this->humanityScore],
            [Category::Authenticity, $this->authenticityScore],
            [Category::Uniqueness, $this->uniquenessScore],
            [Category::Behavior, $this->behaviorScore],
        ];

        $items = [];

        foreach ($pairs as [$category, $value]) {
            if ($value !== null) {
                $items[] = new Score($category, $value);
            }
        }

        return new Scores($items);
    }

    /**
     * Builds an identity from a decoded `GET /api/identities/{id}` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        $badges = [];

        foreach (Payload::objects($payload, 'badges') as $badge) {
            $badges[] = Badge::fromApi($badge);
        }

        return new self(
            Payload::string($payload, 'id'),
            Payload::string($payload, 'displayName'),
            Payload::string($payload, 'displayEmail'),
            Payload::string($payload, 'displayUsername'),
            Payload::int($payload, 'humanityScore'),
            Payload::int($payload, 'authenticityScore'),
            Payload::int($payload, 'uniquenessScore'),
            Payload::int($payload, 'behaviorScore'),
            Dates::parse($payload['createdAt'] ?? null),
            Dates::parse($payload['updatedAt'] ?? null),
            Dates::parse($payload['lastTrackedAt'] ?? null),
            Dates::parse($payload['lastScoredAt'] ?? null),
            Payload::bool($payload, 'disregarded'),
            $badges,
            Payload::map($payload, 'data'),
            $payload,
        );
    }
}
