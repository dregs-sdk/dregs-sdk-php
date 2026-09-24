<?php

declare(strict_types=1);

namespace Dregs\Http;

use Dregs\Exception\ConnectionException;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A transport that hands each request to your application's own PSR-18 client.
 *
 * Reach for this when the application already has an HTTP client configured the way your
 * infrastructure needs it — an egress proxy, a pinned CA bundle, a shared connection pool,
 * request logging, a circuit breaker. Laravel and Symfony both ship one.
 *
 * ```php
 * // Symfony: its PSR-18 client is also its own PSR-17 factory.
 * $psr18 = new Symfony\Component\HttpClient\Psr18Client();
 *
 * $client = new Dregs\Client(httpClient: $psr18);
 * ```
 *
 * ```php
 * // Guzzle, which needs the factories naming separately.
 * $factory = new GuzzleHttp\Psr7\HttpFactory();
 *
 * $client = new Dregs\Client(
 *     httpClient: new Dregs\Http\Psr18Transport(new GuzzleHttp\Client(), $factory, $factory),
 * );
 * ```
 *
 * Note that the timeout and the retry budget then belong to your client, not to this SDK: a
 * `timeout` passed to {@see \Dregs\Client} configures the cURL transport it would otherwise
 * have built, and has no way to reach through a PSR-18 client you supplied. PSR-18 also has
 * no separate timeout failure, so a timed-out request arrives here as a network error and is
 * reported as a {@see ConnectionException} rather than a
 * {@see \Dregs\Exception\TimeoutException}.
 *
 * This class refers to interfaces from `psr/http-client` and `psr/http-factory`, which are
 * suggested rather than required. Composer will have installed them already if you have a
 * PSR-18 client to pass.
 */
final class Psr18Transport implements Transport
{
    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param ClientInterface               $httpClient     The PSR-18 client to send through.
     * @param RequestFactoryInterface|null  $requestFactory A PSR-17 request factory. Optional
     *                                                      when the client is one itself, as
     *                                                      Symfony's and Nyholm's are.
     * @param StreamFactoryInterface|null   $streamFactory  A PSR-17 stream factory, under the
     *                                                      same rule.
     *
     * @throws InvalidArgumentException The client is not its own factory and none was given.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $requestFactory ??= $httpClient instanceof RequestFactoryInterface ? $httpClient : null;
        $streamFactory ??= $httpClient instanceof StreamFactoryInterface ? $httpClient : null;

        if ($requestFactory === null || $streamFactory === null) {
            throw new InvalidArgumentException(
                'A PSR-18 client needs PSR-17 factories alongside it. Pass a request factory '
                . 'and a stream factory to Dregs\Http\Psr18Transport, or install one of the '
                . 'PSR-18 clients that doubles as its own factory. Guzzle users want '
                . 'GuzzleHttp\Psr7\HttpFactory, which serves as both.'
            );
        }

        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Sends one request and returns the response.
     *
     * @param array<string, string> $headers
     *
     * @throws ConnectionException The PSR-18 client could not complete the request.
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ConnectionException(
                "Could not reach Dregs at {$url}: {$exception->getMessage()}.",
                0,
                $exception
            );
        }

        $collected = [];

        foreach (array_keys($response->getHeaders()) as $name) {
            $collected[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return new Response($response->getStatusCode(), $collected, (string) $response->getBody());
    }
}
