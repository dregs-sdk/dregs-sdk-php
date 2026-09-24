<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 429. The credential exceeded its request rate limit.
 *
 * The client already retried this with backoff before giving up. When you catch one, back off
 * further rather than looping immediately: `retryAfter` carries the server's own advice in
 * seconds when the response included a `Retry-After` header.
 */
class RateLimitException extends ApiException
{
    /**
     * @param string      $description The message Dregs sent, or a reason phrase.
     * @param int         $statusCode  The HTTP status code, 429 unless the outcome came back
     *                                 in the body of a 200.
     * @param mixed       $body        The decoded JSON body, or null.
     * @param string|null $requestId   The `X-Request-Id` response header, when present.
     * @param float|null  $retryAfter  Seconds to wait before retrying, from the `Retry-After`
     *                                 header when the response carried one.
     */
    public function __construct(
        string $description,
        int $statusCode = 429,
        mixed $body = null,
        ?string $requestId = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($description, $statusCode, $body, $requestId);
    }
}
