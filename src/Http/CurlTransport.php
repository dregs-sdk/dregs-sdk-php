<?php

declare(strict_types=1);

namespace Dregs\Http;

use CurlHandle;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\TimeoutException;

/**
 * The default transport, built on ext-curl.
 *
 * This exists so that `new Dregs\Client($secretKey)` works in a project with no HTTP client
 * of its own and nothing else installed. It opens one connection per request and keeps no
 * pool, which is the right trade for the handful of calls a server makes per user action. If
 * your application already has a configured HTTP client, with its own proxy and TLS settings,
 * hand that to {@see Psr18Transport} instead.
 */
final class CurlTransport implements Transport
{
    /** cURL's error code for a request that ran out of time. */
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /**
     * @param float $timeout Seconds before a request is abandoned. Covers the whole request,
     *                       not just establishing the connection.
     */
    public function __construct(private readonly float $timeout = 10.0)
    {
    }

    /**
     * Sends one request and returns the response.
     *
     * @param array<string, string> $headers
     *
     * @throws TimeoutException    The request did not finish within the timeout.
     * @throws ConnectionException cURL could not complete the request.
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $handle = curl_init();

        if (!$handle instanceof CurlHandle) {
            throw new ConnectionException('Could not initialise a cURL handle.');
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        $collect = static function (CurlHandle $curl, string $line) use (&$responseHeaders): int {
            /** @var array<string, string> $responseHeaders */
            self::collectHeader($line, $responseHeaders);

            return strlen($line);
        };

        $timeoutMs = max(1, (int) round($this->timeout * 1000));

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // Sub-second timeouts are unreliable while cURL is allowed to use signals.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => self::formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => $collect,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errorNumber !== 0 || !is_string($raw)) {
            if ($errorNumber === self::CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException("Request to {$url} timed out after {$this->timeout}s.");
            }

            $detail = $errorMessage !== '' ? $errorMessage : 'the request failed';

            throw new ConnectionException("Could not reach Dregs at {$url}: {$detail}.");
        }

        return new Response(is_int($statusCode) ? $statusCode : 0, $responseHeaders, $raw);
    }

    /**
     * Turns a name-keyed header map into the "Name: value" list cURL wants.
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        return $formatted;
    }

    /**
     * Folds one raw header line into the collected map, skipping the status line.
     *
     * @param array<string, string> $collected
     */
    private static function collectHeader(string $line, array &$collected): void
    {
        $position = strpos($line, ':');

        if ($position === false) {
            return;
        }

        $name = strtolower(trim(substr($line, 0, $position)));

        if ($name === '') {
            return;
        }

        $value = trim(substr($line, $position + 1));

        $collected[$name] = isset($collected[$name]) ? "{$collected[$name]}, {$value}" : $value;
    }
}
