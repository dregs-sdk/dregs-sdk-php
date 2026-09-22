<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Exception\ServerException;
use Dregs\Exception\TimeoutException;
use Dregs\Tests\Support\FakeTransport;
use Dregs\Tests\Support\RecordingClient;
use Dregs\Tests\Support\Responses;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What gets retried, how often, and how long the client waits in between.
 */
final class RetryTest extends TestCase
{
    /**
     * @return list<array{int, bool}>
     */
    public static function statuses(): array
    {
        return [
            [408, true],
            [429, true],
            [500, true],
            [502, true],
            [503, true],
            [504, true],
            [400, false],
            [401, false],
            [402, false],
            [403, false],
            [404, false],
            [409, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testOnlyTransientStatusesAreWorthRetrying(int $status, bool $expected): void
    {
        self::assertSame($expected, $this->client()->retriesAfter(0, $status));
    }

    public function testATransportFailureIsAlwaysWorthRetrying(): void
    {
        self::assertTrue($this->client()->retriesAfter(0, null));
    }

    public function testTheBudgetIsRespected(): void
    {
        $client = $this->client(maxRetries: 2);

        self::assertTrue($client->retriesAfter(0, 503));
        self::assertTrue($client->retriesAfter(1, 503));
        self::assertFalse($client->retriesAfter(2, 503));
    }

    public function testAServerErrorIsRetriedAndCanSucceed(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::empty(503),
            Responses::json(200, ['id' => 'user_12345']),
        );

        $client = $this->client($transport, maxRetries: 2);

        self::assertSame('user_12345', $client->identities->get('user_12345')->id);
        self::assertSame(2, $transport->callCount());
    }

    public function testRetriesStopAtTheConfiguredLimit(): void
    {
        $transport = FakeTransport::always(Responses::empty(500));
        $client = $this->client($transport, maxRetries: 2);

        try {
            $client->identities->get('user_12345');

            self::fail('An unrecoverable 500 should have thrown.');
        } catch (ServerException) {
            // The first attempt plus two retries.
            self::assertSame(3, $transport->callCount());
            self::assertCount(2, $client->sleeps);
        }
    }

    public function testARateLimitIsRetried(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::empty(429, ['Retry-After' => '0']),
            Responses::json(200, ['status' => 'success', 'id' => 'evt_1']),
        );

        $client = $this->client($transport, maxRetries: 2);

        self::assertTrue($client->track('user.signup', 'user_12345')->isAccepted());
        self::assertSame(2, $transport->callCount());
        self::assertSame([0.0], $client->sleeps);
    }

    public function testARetriedEventKeepsItsIdSoIngestionStaysIdempotent(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::empty(503),
            Responses::json(200, ['status' => 'success', 'id' => 'evt_1']),
        );

        $this->client($transport, maxRetries: 2)->track('user.signup', 'user_12345');

        self::assertSame(
            $transport->request(0)->stringField('id'),
            $transport->request(1)->stringField('id')
        );
    }

    public function testAClientErrorIsNotRetried(): void
    {
        $transport = FakeTransport::always(Responses::empty(404));

        try {
            $this->client($transport, maxRetries: 2)->identities->get('user_12345');

            self::fail('A 404 should have thrown.');
        } catch (NotFoundException) {
            self::assertSame(1, $transport->callCount());
        }
    }

    public function testAConnectionFailureIsRetriedThenThrown(): void
    {
        $transport = (new FakeTransport())->queue(
            new ConnectionException('refused'),
            new ConnectionException('refused'),
            new ConnectionException('refused'),
        );

        try {
            $this->client($transport, maxRetries: 2)->identities->get('user_12345');

            self::fail('A persistent connection failure should have thrown.');
        } catch (ConnectionException) {
            self::assertSame(3, $transport->callCount());
        }
    }

    public function testAConnectionFailureRecoversWhenARetryLands(): void
    {
        $transport = (new FakeTransport())->queue(
            new ConnectionException('refused'),
            Responses::json(200, ['id' => 'user_12345']),
        );

        self::assertSame(
            'user_12345',
            $this->client($transport, maxRetries: 2)->identities->get('user_12345')->id
        );
    }

    public function testATimeoutIsRetriedToo(): void
    {
        $transport = (new FakeTransport())->queue(
            new TimeoutException('slow'),
            Responses::json(200, ['id' => 'user_12345']),
        );

        self::assertSame(
            'user_12345',
            $this->client($transport, maxRetries: 2)->identities->get('user_12345')->id
        );
    }

    public function testNothingIsRetriedWhenTheBudgetIsZero(): void
    {
        $transport = FakeTransport::always(Responses::empty(503));
        $client = $this->client($transport, maxRetries: 0);

        try {
            $client->identities->get('user_12345');

            self::fail('A 503 should have thrown.');
        } catch (ServerException) {
            self::assertSame(1, $transport->callCount());
            self::assertSame([], $client->sleeps);
        }
    }

    public function testRetryAfterWinsOverTheClientsOwnBackoff(): void
    {
        self::assertSame(2.5, $this->client()->backoffFor(0, 2.5));
        self::assertSame(0.0, $this->client()->backoffFor(3, 0.0));
    }

    public function testAnAbsurdRetryAfterIsCapped(): void
    {
        self::assertSame(60.0, $this->client()->backoffFor(0, 86_400.0));
    }

    public function testANegativeRetryAfterIsIgnored(): void
    {
        self::assertLessThanOrEqual(0.5, $this->client()->backoffFor(0, -1.0));
    }

    public function testTheBackoffGrowsWithEachAttemptAndStaysJittered(): void
    {
        $client = $this->client();

        // Full jitter: each wait is somewhere in [0, window), and the window doubles.
        foreach ([0 => 0.5, 1 => 1.0, 2 => 2.0, 3 => 4.0, 4 => 8.0] as $attempt => $window) {
            $backoff = $client->backoffFor($attempt);

            self::assertGreaterThanOrEqual(0.0, $backoff);
            self::assertLessThanOrEqual($window, $backoff);
        }
    }

    public function testTheBackoffWindowStopsGrowing(): void
    {
        self::assertLessThanOrEqual(8.0, $this->client()->backoffFor(30));
    }

    public function testTheBackoffIsNotAlwaysTheSameNumber(): void
    {
        $client = $this->client();

        $samples = [];

        for ($i = 0; $i < 20; ++$i) {
            $samples[] = $client->backoffFor(4);
        }

        self::assertGreaterThan(1, count(array_unique($samples)), 'The backoff should be jittered.');
    }

    public function testARetriedRateLimitWaitsAsLongAsTheServerAsked(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::empty(429, ['Retry-After' => '3']),
            Responses::json(200, ['status' => 'success', 'id' => 'evt_1']),
        );

        $client = $this->client($transport, maxRetries: 2);
        $client->track('user.signup', 'user_12345');

        self::assertSame([3.0], $client->sleeps);
    }

    public function testTheDocumentedRetryStatusesAreTheOnesUsed(): void
    {
        self::assertSame([408, 429, 500, 502, 503, 504], Client::RETRY_STATUSES);
    }

    private function client(?FakeTransport $transport = null, int $maxRetries = 2): RecordingClient
    {
        return new RecordingClient(
            self::SECRET_KEY,
            self::BASE_URL,
            maxRetries: $maxRetries,
            httpClient: $transport ?? FakeTransport::alwaysJson(['id' => 'user_12345']),
        );
    }
}
