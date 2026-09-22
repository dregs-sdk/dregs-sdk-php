<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * Dregs answered, and the answer was an error.
 *
 * Every status Dregs uses has its own subclass, so you can catch the one case you handle
 * differently and leave the rest to a broader `catch`. The status, the parsed body, and the
 * request id are all on the exception, and the request id is the thing to quote when asking
 * Dregs support about a specific failure.
 */
class ApiException extends DregsException
{
    /**
     * @param string               $description The message Dregs sent, or a reason phrase.
     * @param int                  $statusCode  The HTTP status code.
     * @param mixed                $body        The decoded JSON body, or null when the
     *                                          response was not JSON.
     * @param string|null          $requestId   The `X-Request-Id` response header, when the
     *                                          response carried one.
     */
    public function __construct(
        public readonly string $description,
        public readonly int $statusCode,
        public readonly mixed $body = null,
        public readonly ?string $requestId = null,
    ) {
        $suffix = $requestId !== null && $requestId !== '' ? " (request {$requestId})" : '';

        parent::__construct("HTTP {$statusCode}: {$description}{$suffix}", $statusCode);
    }
}
