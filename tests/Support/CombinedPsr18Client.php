<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-18 client that is also its own PSR-17 factory, as Symfony's `Psr18Client` is.
 *
 * The SDK is supposed to notice that and stop asking for factories, which is what the test
 * around this class checks.
 */
final class CombinedPsr18Client implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    private readonly Psr17Factory $factory;

    public function __construct(private readonly string $body = '{}', private readonly int $status = 200)
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param string|UriInterface $uri
     */
    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->factory->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->factory->createStreamFromFile($filename, $mode);
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->factory->createStreamFromResource($resource);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return $this->factory
            ->createResponse($this->status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($this->body));
    }
}
