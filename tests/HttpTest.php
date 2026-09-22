<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Exception\ConnectionException;
use Dregs\Http\CurlTransport;
use Dregs\Http\Psr18Transport;
use Dregs\Http\Response;
use Dregs\Tests\Support\CombinedPsr18Client;
use Dregs\Tests\Support\FailingPsr18Client;
use Dregs\Tests\Support\Items;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The transports, and the response value object they hand back.
 */
final class HttpTest extends TestCase
{
    public function testAResponseFindsAHeaderCaseInsensitively(): void
    {
        $response = new Response(200, ['x-request-id' => 'req_abc'], '{}');

        self::assertSame('req_abc', $response->header('X-Request-Id'));
        self::assertSame('req_abc', $response->header('x-request-id'));
        self::assertNull($response->header('X-Missing'));
    }

    public function testAResponseDefaultsToNoHeadersAndNoBody(): void
    {
        $response = new Response(204);

        self::assertSame(204, $response->statusCode);
        self::assertSame([], $response->headers);
        self::assertSame('', $response->body);
    }

    public function testTheCurlTransportRefusesAnUnreachableHost(): void
    {
        // .invalid never resolves, by RFC 2606, so this exercises the failure path without
        // touching the network in any meaningful way.
        $transport = new CurlTransport(2.0);

        $this->expectException(ConnectionException::class);

        $transport->send('GET', 'https://dregs.test.invalid/api/identities/x', [], null);
    }

    public function testThePsr18TransportSendsTheMethodUrlHeadersAndBody(): void
    {
        $psr18 = new CombinedPsr18Client('{"status":"success","id":"evt_1"}');

        $transport = new Psr18Transport($psr18);
        $transport->send('POST', 'https://example.test/api/events', ['X-Tenant' => 'acme'], '{"a":1}');

        self::assertCount(1, $psr18->sent);

        $request = Items::first($psr18->sent);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/api/events', (string) $request->getUri());
        self::assertSame('acme', $request->getHeaderLine('X-Tenant'));
        self::assertSame('{"a":1}', (string) $request->getBody());
    }

    public function testThePsr18TransportReturnsTheStatusHeadersAndBody(): void
    {
        $transport = new Psr18Transport(new CombinedPsr18Client('{"id":"user_12345"}', 201));

        $response = $transport->send('GET', 'https://example.test/api/identities/user_12345', [], null);

        self::assertSame(201, $response->statusCode);
        self::assertSame('{"id":"user_12345"}', $response->body);
        self::assertSame('application/json', $response->header('Content-Type'));
    }

    public function testThePsr18TransportTurnsAClientFailureIntoAConnectionException(): void
    {
        $factory = new Psr17Factory();

        $transport = new Psr18Transport(new FailingPsr18Client('the socket went away'), $factory, $factory);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/the socket went away/');

        $transport->send('GET', 'https://example.test/api/identities/x', [], null);
    }

    public function testThePsr18TransportNeedsFactoriesItCannotFindItself(): void
    {
        $bare = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PSR-17 factories/');

        new Psr18Transport($bare);
    }

    public function testAClientDrivenThroughAPsr18TransportWorksEndToEnd(): void
    {
        $psr18 = new CombinedPsr18Client('{"status":"success","id":"evt_1"}');

        $client = new Client(self::SECRET_KEY, self::BASE_URL, httpClient: new Psr18Transport($psr18));

        self::assertTrue($client->track('user.signup', 'user_12345')->isAccepted());
        self::assertSame(
            'Bearer ' . self::SECRET_KEY,
            Items::first($psr18->sent)->getHeaderLine('Authorization')
        );
    }
}
