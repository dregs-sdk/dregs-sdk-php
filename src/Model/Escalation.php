<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * An escalation an escalation rule opened against an identity.
 */
final readonly class Escalation
{
    /**
     * @param int|null               $id             The escalation's id.
     * @param string|null            $status         "OPEN", "ACKNOWLEDGED", or "CLOSED".
     * @param string|null            $severity       "INFO", "WARNING", or "CRITICAL".
     * @param Reference|null         $identity       The identity the escalation is about.
     * @param Reference|null         $rule           The escalation rule that opened it.
     * @param array<string, mixed>   $scores         The identity's scores when the
     *                                               escalation opened, by category name.
     * @param DateTimeImmutable|null $openedAt       When it opened.
     * @param DateTimeImmutable|null $acknowledgedAt When it was acknowledged, if it has been.
     * @param DateTimeImmutable|null $closedAt       When it was closed, if it has been.
     * @param DateTimeImmutable|null $createdAt      When Dregs recorded it.
     * @param array<string, mixed>   $raw            The response body as received.
     */
    public function __construct(
        public ?int $id,
        public ?string $status = null,
        public ?string $severity = null,
        public ?Reference $identity = null,
        public ?Reference $rule = null,
        public array $scores = [],
        public ?DateTimeImmutable $openedAt = null,
        public ?DateTimeImmutable $acknowledgedAt = null,
        public ?DateTimeImmutable $closedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Whether the escalation is still open, neither acknowledged nor closed.
     */
    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    /**
     * Builds an escalation from a decoded `GET /api/escalations/{id}` response, or from one
     * item of a list.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        $identity = $payload['identity'] ?? null;
        $rule = $payload['escalationRule'] ?? null;

        return new self(
            Payload::int($payload, 'id'),
            Payload::string($payload, 'status'),
            Payload::string($payload, 'severity'),
            is_array($identity) ? Reference::fromApi(Payload::asMap($identity)) : null,
            is_array($rule) ? Reference::fromApi(Payload::asMap($rule)) : null,
            Payload::map($payload, 'scoreSnapshot'),
            Dates::parse($payload['openedAt'] ?? null),
            Dates::parse($payload['acknowledgedAt'] ?? null),
            Dates::parse($payload['closedAt'] ?? null),
            Dates::parse($payload['createdAt'] ?? null),
            $payload,
        );
    }
}
