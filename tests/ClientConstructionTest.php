<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Http\CurlTransport;
use Dregs\Tests\Support\CombinedPsr18Client;
use Dregs\Tests\Support\FakeTransport;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Where the client takes its configuration from, and what it refuses.
 */
final class ClientConstructionTest extends TestCase
{
    public function testReadsTheSecretKeyFromTheEnvironment(): void
    {
        $this->setEnvironment(Client::SECRET_KEY_ENV, self::SECRET_KEY);

        $client = new Client();

        self::assertSame(Client::DEFAULT_BASE_URL, $client->baseUrl);
    }

    public function testAnExplicitKeyBeatsTheEnvironment(): void
    {
        $this->setEnvironment(Client::SECRET_KEY_ENV, 'sk_from_the_environment');

        $transport = FakeTransport::alwaysJson(['status' => 'success', 'id' => 'evt_1']);

        $client = new Client(self::SECRET_KEY, self::BASE_URL, httpClient: $transport);
        $client->track('user.signup', 'user_12345');

        self::assertSame('Bearer ' . self::SECRET_KEY, $transport->lastRequest()->header('Authorization'));
    }

    public function testReadsTheBaseUrlFromTheEnvironment(): void
    {
        $this->setEnvironment(Client::BASE_URL_ENV, 'https://staging.example.com/api');

        $client = new Client(self::SECRET_KEY);

        self::assertSame('https://staging.example.com/api', $client->baseUrl);
    }

    public function testAnExplicitBaseUrlBeatsTheEnvironment(): void
    {
        $this->setEnvironment(Client::BASE_URL_ENV, 'https://staging.example.com/api');

        self::assertSame(self::BASE_URL, (new Client(self::SECRET_KEY, self::BASE_URL))->baseUrl);
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        self::assertSame(self::BASE_URL, (new Client(self::SECRET_KEY, self::BASE_URL . '/'))->baseUrl);
    }

    public function testFallsBackToTheDocumentedBaseUrl(): void
    {
        self::assertSame('https://dregs.com/api', (new Client(self::SECRET_KEY))->baseUrl);
    }

    public function testAMissingKeyNamesTheEnvironmentVariable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DREGS_SECRET_KEY/');

        new Client();
    }

    public function testAPublicKeyIsRefusedWithAnExplanation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/public key/');

        new Client('pk_abcdefghQijklmQabcdefghijklmn');
    }

    public function testAPublicKeyInTheEnvironmentIsRefusedToo(): void
    {
        $this->setEnvironment(Client::SECRET_KEY_ENV, 'pk_abcdefghQijklmQabcdefghijklmn');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/public key/');

        new Client();
    }

    public function testNegativeRetriesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maxRetries/');

        new Client(self::SECRET_KEY, maxRetries: -1);
    }

    public function testTheRetryBudgetIsReadable(): void
    {
        self::assertSame(Client::DEFAULT_MAX_RETRIES, (new Client(self::SECRET_KEY))->maxRetries);
        self::assertSame(5, (new Client(self::SECRET_KEY, maxRetries: 5))->maxRetries);
    }

    public function testTheIdentitiesNamespaceIsReady(): void
    {
        self::assertInstanceOf(\Dregs\Identities::class, (new Client(self::SECRET_KEY))->identities);
    }

    public function testATransportIsUsedAsGiven(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'user_12345']);

        $client = new Client(self::SECRET_KEY, self::BASE_URL, httpClient: $transport);
        $client->identities->get('user_12345');

        self::assertSame(1, $transport->callCount());
    }

    public function testAPsr18ClientIsWrappedInATransport(): void
    {
        $factory = new Psr17Factory();
        $psr18 = $this->psr18Returning($factory->createResponse(200)->withBody(
            $factory->createStream('{"id": "user_12345"}')
        ));

        $client = new Client(
            self::SECRET_KEY,
            self::BASE_URL,
            httpClient: $psr18,
            requestFactory: $factory,
            streamFactory: $factory,
        );

        self::assertSame('user_12345', $client->identities->get('user_12345')->id);
    }

    public function testAPsr18ClientWithoutFactoriesIsRefusedWithAdvice(): void
    {
        $psr18 = $this->psr18Returning((new Psr17Factory())->createResponse(200));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PSR-17 factories/');

        new Client(self::SECRET_KEY, self::BASE_URL, httpClient: $psr18);
    }

    public function testAPsr18ClientThatIsItsOwnFactoryNeedsNothingElse(): void
    {
        // Symfony's PSR-18 client is a request factory and a stream factory at once; a client
        // like that should not have to be handed factories it already is.
        $psr18 = new CombinedPsr18Client('{"id": "ada"}');

        $client = new Client(self::SECRET_KEY, self::BASE_URL, httpClient: $psr18);

        self::assertSame('ada', $client->identities->get('ada')->id);
        self::assertCount(1, $psr18->sent);
    }

    public function testTheDefaultTransportNeedsNothingInstalled(): void
    {
        // The point of the cURL transport: a client built with nothing but the key is usable,
        // with no PSR-18 implementation anywhere in the project.
        self::assertInstanceOf(CurlTransport::class, new CurlTransport());
        self::assertSame(
            Client::DEFAULT_BASE_URL,
            (new Client(self::SECRET_KEY))->baseUrl,
            'A client built with only a key should be ready to use.'
        );
    }

    private function psr18Returning(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
