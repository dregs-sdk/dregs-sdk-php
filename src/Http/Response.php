<?php

declare(strict_types=1);

namespace Dregs\Http;

/**
 * A raw HTTP response, as a {@see Transport} hands it back.
 *
 * Deliberately small: a status, the headers, and the body as received. The client decodes the
 * body and decides what the status means, so a transport never has to.
 */
final readonly class Response
{
    /**
     * @param int                   $statusCode The HTTP status code.
     * @param array<string, string> $headers    Response headers, keyed by lower-cased name.
     *                                          Repeated headers are joined with ", ".
     * @param string                $body       The response body, exactly as received.
     */
    public function __construct(
        public int $statusCode,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * Returns a header's value by name, case-insensitively, or null when it is absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
