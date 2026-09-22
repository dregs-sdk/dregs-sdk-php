<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Exception\ApiException;
use Dregs\Exception\AuthenticationException;
use Dregs\Exception\BadRequestException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\DregsException;
use Dregs\Exception\NotFoundException;
use Dregs\Exception\PermissionDeniedException;
use Dregs\Exception\QuotaExceededException;
use Dregs\Exception\RateLimitException;
use Dregs\Exception\ServerException;
use Dregs\Exception\TimeoutException;
use Dregs\Tests\Support\FakeTransport;
use Dregs\Tests\Support\Responses;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Statuses map to typed exceptions, and the exceptions carry what a caller needs.
 */
final class ErrorTest extends TestCase
{
    /**
     * @return list<array{int, class-string<ApiException>}>
     */
    public static function statuses(): array
    {
        return [
            [400, BadRequestException::class],
            [401, AuthenticationException::class],
            [402, QuotaExceededException::class],
            [403, PermissionDeniedException::class],
            [404, NotFoundException::class],
            [429, RateLimitException::class],
            [500, ServerException::class],
            [502, ServerException::class],
            [503, ServerException::class],
            [504, ServerException::class],
        ];
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('statuses')]
    public function testEachStatusThrowsItsOwnException(int $status, string $expected): void
    {
        $client = $this->client(FakeTransport::always(Responses::error($status, 'No')));

        try {
            $client->identities->get('user_12345');

            self::fail("HTTP {$status} should have thrown {$expected}.");
        } catch (ApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame($status, $exception->statusCode);
        }
    }

    public function testAnUnmappedClientStatusFallsBackToTheBaseApiException(): void
    {
        $client = $this->client(FakeTransport::always(Responses::error(418, 'No tea here')));

        try {
            $client->identities->get('user_12345');

            self::fail('An unmapped status should still have thrown.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(418, $exception->statusCode);
        }
    }

    public function testEveryFailureIsCatchableAsTheBaseClass(): void
    {
        $this->expectException(DregsException::class);

        $this->client(FakeTransport::always(Responses::empty(404)))->identities->get('user_12345');
    }

    public function testTheApiMessageReachesTheException(): void
    {
        $client = $this->client(FakeTransport::always(Responses::error(404, 'Not Found')));

        try {
            $client->identities->get('user_12345');

            self::fail('A 404 should have thrown.');
        } catch (NotFoundException $exception) {
            self::assertSame('Not Found', $exception->description);
            self::assertStringContainsString('404', $exception->getMessage());
            self::assertStringContainsString('Not Found', $exception->getMessage());
        }
    }

    public function testTheParsedBodyIsCarriedThrough(): void
    {
        $client = $this->client(FakeTransport::always(Responses::error(400, 'Bad')));

        try {
            $client->identities->get('user_12345');

            self::fail('A 400 should have thrown.');
        } catch (BadRequestException $exception) {
            $body = $exception->body;

            if (!is_array($body)) {
                self::fail('The error body should have been decoded.');
            }

            self::assertSame('Bad', $body['message'] ?? null);
            self::assertSame(400, $body['status'] ?? null);
        }
    }

    public function testARequestIdIsCarriedThrough(): void
    {
        $transport = FakeTransport::always(Responses::empty(500, ['X-Request-Id' => 'req_abc']));

        try {
            $this->client($transport)->identities->get('user_12345');

            self::fail('A 500 should have thrown.');
        } catch (ServerException $exception) {
            self::assertSame('req_abc', $exception->requestId);
            self::assertStringContainsString('req_abc', $exception->getMessage());
        }
    }

    public function testANonJsonErrorStillThrowsTheRightClass(): void
    {
        $transport = FakeTransport::always(Responses::text(502, '<html>gateway</html>'));

        try {
            $this->client($transport)->identities->get('user_12345');

            self::fail('A 502 should have thrown.');
        } catch (ServerException $exception) {
            self::assertNull($exception->body);
            self::assertNull($exception->requestId);
        }
    }

    public function testRetryAfterIsExposed(): void
    {
        $transport = FakeTransport::always(
            Responses::json(429, ['status' => 'rate_limited'], ['Retry-After' => '2'])
        );

        try {
            $this->client($transport)->track('user.signup', 'user_12345');

            self::fail('A 429 should have thrown.');
        } catch (RateLimitException $exception) {
            self::assertSame(2.0, $exception->retryAfter);
        }
    }

    public function testAnHttpDateRetryAfterIsIgnoredRatherThanMisread(): void
    {
        $transport = FakeTransport::always(
            Responses::empty(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'])
        );

        try {
            $this->client($transport)->track('user.signup', 'user_12345');

            self::fail('A 429 should have thrown.');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfter);
        }
    }

    public function testARateLimitReportedInTheBodyStillThrows(): void
    {
        // Older API builds answered the ingestion limit with HTTP 200 and a body status.
        $transport = FakeTransport::alwaysJson(['status' => 'rate_limited', 'id' => null]);

        try {
            $this->client($transport)->track('user.signup', 'user_12345');

            self::fail('A body-level rate limit should have thrown.');
        } catch (RateLimitException $exception) {
            self::assertSame(429, $exception->statusCode);
        }
    }

    public function testAQuotaReportedInTheBodyStillThrows(): void
    {
        $transport = FakeTransport::alwaysJson(['status' => 'quota_exceeded', 'id' => null]);

        try {
            $this->client($transport)->track('user.signup', 'user_12345');

            self::fail('A body-level quota failure should have thrown.');
        } catch (QuotaExceededException $exception) {
            self::assertSame(402, $exception->statusCode);
        }
    }

    public function testASuccessfulBodyStatusIsNotMistakenForAFailure(): void
    {
        $transport = FakeTransport::alwaysJson(['status' => 'success', 'id' => 'evt_1']);

        self::assertTrue($this->client($transport)->track('user.signup', 'user_12345')->isAccepted());
    }

    public function testATimeoutThrowsItsOwnException(): void
    {
        $transport = (new FakeTransport())->queue(new TimeoutException('slow'));

        $this->expectException(TimeoutException::class);

        $this->client($transport)->identities->get('user_12345');
    }

    public function testATimeoutIsCatchableAsAConnectionFailure(): void
    {
        $transport = (new FakeTransport())->queue(new TimeoutException('slow'));

        $this->expectException(ConnectionException::class);

        $this->client($transport)->identities->get('user_12345');
    }

    public function testAConnectionFailureThrowsItsOwnException(): void
    {
        $transport = (new FakeTransport())->queue(new ConnectionException('refused'));

        $this->expectException(ConnectionException::class);

        $this->client($transport)->identities->get('user_12345');
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(self::SECRET_KEY, self::BASE_URL, maxRetries: 0, httpClient: $transport);
    }
}
