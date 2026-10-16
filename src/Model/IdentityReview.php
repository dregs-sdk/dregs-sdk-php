<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * A review of an identity's analysis, written by Dregs's AI reviewer.
 */
final readonly class IdentityReview
{
    /**
     * @param int|null               $id           The review's id.
     * @param string|null            $identityId   The identity that was reviewed.
     * @param string|null            $status       "PENDING" until the review has run, then
     *                                             "FINISHED" or "FAILED".
     * @param string|null            $feedback     The note you sent with the request, if any.
     * @param string|null            $summary      The reviewer's findings, once it has run.
     * @param string|null            $errorMessage Why the review failed, when it did.
     * @param DateTimeImmutable|null $createdAt    When the review was requested.
     * @param DateTimeImmutable|null $completedAt  When it finished.
     * @param array<string, mixed>   $raw          The review's body as received.
     */
    public function __construct(
        public ?int $id,
        public ?string $identityId = null,
        public ?string $status = null,
        public ?string $feedback = null,
        public ?string $summary = null,
        public ?string $errorMessage = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Whether the reviewer is still working on it.
     */
    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    /**
     * Builds a review from a decoded `POST /api/identities/{id}/actions/review` response, or
     * from one item of the reviews list.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::int($payload, 'id'),
            Payload::string($payload, 'identityId'),
            Payload::string($payload, 'status'),
            Payload::string($payload, 'userFeedback'),
            Payload::string($payload, 'summary'),
            Payload::string($payload, 'errorMessage'),
            Dates::parse($payload['createdAt'] ?? null),
            Dates::parse($payload['completedAt'] ?? null),
            $payload,
        );
    }
}
