<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use Dregs\Client;

/**
 * A client that records how long it would have waited instead of actually waiting.
 *
 * The retry tests need to assert on the backoff without spending it, and `sleepFor()` is
 * documented as overridable for exactly this.
 */
final class RecordingClient extends Client
{
    /** @var list<float> */
    public array $sleeps = [];

    /**
     * The backoff the client would use, exposed so it can be asserted on directly.
     */
    public function backoffFor(int $attempt, ?float $retryAfter = null): float
    {
        return $this->backoffSeconds($attempt, $retryAfter);
    }

    /**
     * Whether the client would try again, exposed for the same reason.
     */
    public function retriesAfter(int $attempt, ?int $statusCode): bool
    {
        return $this->shouldRetry($attempt, $statusCode);
    }

    protected function sleepFor(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
