<?php

declare(strict_types=1);

namespace Dregs\Http;

use Dregs\Exception\ConnectionException;
use Dregs\Exception\TimeoutException;

/**
 * How the client puts a request on the wire.
 *
 * Two implementations ship with the SDK: {@see CurlTransport}, which is what you get by
 * default and needs nothing installed, and {@see Psr18Transport}, which hands the request to
 * your application's own PSR-18 client. Implement this yourself if you need something else —
 * a transport that records requests in tests, one that routes through a queue, or one built
 * on a client neither of those covers.
 *
 * An implementation is responsible for exactly one thing: turning a request into a
 * {@see Response}, or throwing when it could not. It never inspects the status code, decodes
 * the body, or retries. All of that belongs to the client.
 */
interface Transport
{
    /**
     * Sends one request and returns the response.
     *
     * @param string                $method  The HTTP method, upper-cased.
     * @param string                $url     The absolute URL to request.
     * @param array<string, string> $headers Request headers, keyed by name.
     * @param string|null           $body    The request body, or null for a request without
     *                                       one.
     *
     * @throws TimeoutException    The request did not finish within the configured timeout.
     * @throws ConnectionException The request never reached the server.
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
