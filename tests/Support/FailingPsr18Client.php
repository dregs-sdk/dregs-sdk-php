<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that only ever fails the way a network fault does.
 */
final class FailingPsr18Client implements ClientInterface
{
    public function __construct(private readonly string $reason = 'the connection was refused')
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new Psr18NetworkException($this->reason, $request);
    }
}
