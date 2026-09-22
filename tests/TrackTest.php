<?php

declare(strict_types=1);

namespace Dregs\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Dregs\Client;
use Dregs\Tests\Support\FakeTransport;
use Dregs\Tests\Support\Responses;
use InvalidArgumentException;

/**
 * The body, headers, and result of `track()`.
 */
final class TrackTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = FakeTransport::alwaysJson(['status' => 'success', 'id' => 'evt_1']);
    }

    public function testSendsTheDocumentedBody(): void
    {
        $this->client()->track(
            'user.signup',
            identity: 'user_12345',
            data: ['plan' => 'pro'],
            identityData: ['email' => 'ada@example.com'],
            eventId: 'signup-991',
        );

        $body = $this->transport->lastRequest()->json();

        self::assertSame('signup-991', $body['id']);
        self::assertSame('user.signup', $body['type']);
        self::assertSame(['plan' => 'pro'], $body['data']);
        self::assertSame(['id' => 'user_12345', 'data' => ['email' => 'ada@example.com']], $body['identity']);
        self::assertSame('php-sdk', $body['source']);
    }

    public function testPostsToTheEventsEndpoint(): void
    {
        $this->client()->track('user.signup', 'user_12345');

        $request = $this->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE_URL . '/events', $request->url);
    }

    public function testAuthorizesWithTheSecretKey(): void
    {
        $this->client()->track('user.signup', 'user_12345');

        $request = $this->transport->lastRequest();

        self::assertSame('Bearer ' . self::SECRET_KEY, $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame('application/json', $request->header('Content-Type'));
    }

    public function testIdentifiesItselfInTheUserAgent(): void
    {
        $this->client()->track('user.signup', 'user_12345');

        $userAgent = $this->transport->lastRequest()->header('User-Agent');

        self::assertNotNull($userAgent);
        self::assertStringStartsWith('dregs-php/' . Client::VERSION, $userAgent);
        self::assertStringContainsString('php ' . PHP_VERSION, $userAgent);
    }

    public function testEmptyDataIsSentAsAnObjectRatherThanAnArray(): void
    {
        $this->client()->track('user.signup', 'user_12345');

        $body = (string) $this->transport->lastRequest()->body;

        self::assertStringContainsString('"data":{}', $body);
        self::assertStringNotContainsString('"data":[]', $body);
    }

    public function testGeneratesAnEventIdSoRetriesAreIdempotent(): void
    {
        $client = $this->client();

        $client->track('user.signup', 'user_12345');
        $client->track('user.signup', 'user_12345');

        $first = $this->transport->request(0)->stringField('id');
        $second = $this->transport->request(1)->stringField('id');

        self::assertNotSame($first, $second);
        self::assertStringStartsNotWith('dregs-', $first);
        self::assertLessThanOrEqual(Client::MAX_EVENT_ID_LENGTH, strlen($first));
    }

    public function testUsesTheCallersEventIdVerbatim(): void
    {
        $this->client()->track('purchase', 'user_12345', eventId: 'order-4417');

        self::assertSame('order-4417', $this->transport->lastRequest()->stringField('id'));
    }

    public function testSendsATimestampAsUtc(): void
    {
        $this->client()->track(
            'user.signup',
            'user_12345',
            timestamp: new DateTimeImmutable('2026-09-21T14:22:09', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-09-21T14:22:09Z', $this->transport->lastRequest()->stringField('timestamp'));
    }

    public function testConvertsATimestampFromAnotherZone(): void
    {
        $this->client()->track(
            'user.signup',
            'user_12345',
            timestamp: new DateTimeImmutable('2026-09-21T16:22:09', new DateTimeZone('Europe/Berlin')),
        );

        self::assertSame('2026-09-21T14:22:09Z', $this->transport->lastRequest()->stringField('timestamp'));
    }

    public function testOmitsTheTimestampWhenTheCallerDoes(): void
    {
        $this->client()->track('user.signup', 'user_12345');

        self::assertArrayNotHasKey('timestamp', $this->transport->lastRequest()->json());
    }

    public function testAcceptsACustomSource(): void
    {
        $this->client()->track('user.signup', 'user_12345', source: 'billing-worker');

        self::assertSame('billing-worker', $this->transport->lastRequest()->stringField('source'));
    }

    public function testAnEmptyIdentityIsRefusedBeforeAnyRequest(): void
    {
        try {
            $this->client()->track('user.signup', '');

            self::fail('An empty identity should have been refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('identity is required', $exception->getMessage());
        }

        self::assertSame(0, $this->transport->callCount());
    }

    public function testAnEmptyEventTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/eventType is required/');

        $this->client()->track('', 'user_12345');
    }

    public function testAReservedEventIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved/');

        $this->client()->track('user.signup', 'user_12345', eventId: 'dregs-1234');
    }

    public function testAnOverlongEventIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/64 characters/');

        $this->client()->track('user.signup', 'user_12345', eventId: str_repeat('x', 65));
    }

    public function testAnEventIdOfExactlyTheLimitIsAllowed(): void
    {
        $eventId = str_repeat('x', Client::MAX_EVENT_ID_LENGTH);

        $this->client()->track('user.signup', 'user_12345', eventId: $eventId);

        self::assertSame($eventId, $this->transport->lastRequest()->stringField('id'));
    }

    public function testDataThatCannotBeEncodedIsRefusedWithAdvice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be encoded as JSON/');

        $this->client()->track('user.signup', 'user_12345', data: ['blob' => "\xB1\x31"]);
    }

    public function testReportsAnAcceptedEvent(): void
    {
        $transport = FakeTransport::alwaysJson([
            'status' => 'success',
            'id' => 'evt_1',
            'fingerprint' => null,
        ]);

        $result = $this->client($transport)->track('user.signup', 'user_12345');

        self::assertTrue($result->isAccepted());
        self::assertSame('evt_1', $result->id);
        self::assertSame('success', $result->status);
        self::assertNull($result->fingerprint);
    }

    public function testAQuietRejectionIsNotAccepted(): void
    {
        $transport = FakeTransport::alwaysJson(['status' => 'success', 'id' => null]);

        $result = $this->client($transport)->track('user.signup', 'user_12345');

        self::assertFalse($result->isAccepted());
        self::assertNull($result->id);
        self::assertSame('success', $result->status);
    }

    public function testAnEmptyBodyStillYieldsAResult(): void
    {
        $transport = FakeTransport::always(Responses::empty(200));

        $result = $this->client($transport)->track('user.signup', 'user_12345');

        self::assertFalse($result->isAccepted());
        self::assertSame([], $result->raw);
    }

    public function testTheRawResponseIsKept(): void
    {
        $transport = FakeTransport::alwaysJson([
            'status' => 'success',
            'id' => 'evt_1',
            'somethingNew' => 42,
        ]);

        $result = $this->client($transport)->track('user.signup', 'user_12345');

        self::assertSame(42, $result->raw['somethingNew']);
    }

    private function client(?FakeTransport $transport = null): Client
    {
        return new Client(self::SECRET_KEY, self::BASE_URL, maxRetries: 0, httpClient: $transport ?? $this->transport);
    }
}
