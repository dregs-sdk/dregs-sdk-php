<?php

declare(strict_types=1);

namespace Dregs\Model;

use Dregs\Internal\Payload;

/**
 * The outcome of a {@see \Dregs\Client::track()} call.
 */
final readonly class TrackResult
{
    /**
     * @param string|null          $status      The status Dregs reported, normally "success".
     * @param string|null          $id          The event's identifier, either the one you
     *                                          supplied or one the SDK generated. Null when
     *                                          the event was not recorded.
     * @param string|null          $fingerprint The device fingerprint Dregs resolved, for
     *                                          events carrying a device signature. Server-side
     *                                          events do not, so this is normally null.
     * @param array<string, mixed> $raw         The response body as received.
     */
    public function __construct(
        public ?string $status,
        public ?string $id,
        public ?string $fingerprint,
        public array $raw = [],
    ) {
    }

    /**
     * Whether Dregs recorded the event.
     *
     * This is false for the handful of rejections Dregs answers quietly rather than naming
     * the check that failed: an event from an origin the credential does not allow, or one
     * carrying a malformed device signature or an unusable event id. Ingestion failures that
     * are yours to act on — a bad request, an unknown key, an exhausted quota, a rate limit —
     * throw instead of landing here.
     *
     * A false here is worth a log line. It means events are being dropped silently, and the
     * cause is nearly always a configuration mistake rather than a passing fault.
     */
    public function isAccepted(): bool
    {
        return $this->id !== null;
    }

    /**
     * Builds a result from a decoded `POST /api/events` response.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'status'),
            Payload::string($payload, 'id'),
            Payload::string($payload, 'fingerprint'),
            $payload,
        );
    }
}
