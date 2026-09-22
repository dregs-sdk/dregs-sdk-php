<?php

declare(strict_types=1);

namespace Dregs\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Dregs\Exception\DregsException;
use Dregs\Exception\WebhookVerificationException;
use Dregs\Webhooks;

/**
 * Webhook signature verification.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_abc123';

    private const SENT_AT = '2026-09-21T14:22:09Z';

    public function testComputeSignatureMatchesAHandRolledHmac(): void
    {
        $payload = $this->payload();

        self::assertSame(
            hash_hmac('sha256', $payload, self::SECRET),
            Webhooks::computeSignature($payload, self::SECRET)
        );
    }

    public function testComputeSignatureIsHexadecimal(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            Webhooks::computeSignature($this->payload(), self::SECRET)
        );
    }

    public function testAGoodSignatureVerifies(): void
    {
        $payload = $this->payload();

        self::assertTrue(Webhooks::verifySignature($payload, $this->sign($payload), self::SECRET));
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $payload = $this->payload();

        self::assertTrue(
            Webhooks::verifySignature($payload, "  {$this->sign($payload)}\n", self::SECRET)
        );
    }

    public function testATamperedBodyFails(): void
    {
        $signature = $this->sign($this->payload());

        self::assertFalse(
            Webhooks::verifySignature($this->payload(['identityId' => 'someone_else']), $signature, self::SECRET)
        );
    }

    public function testTheWrongSecretFails(): void
    {
        $payload = $this->payload();

        self::assertFalse(
            Webhooks::verifySignature($payload, $this->sign($payload, 'whsec_other'), self::SECRET)
        );
    }

    public function testAnEmptySignatureFails(): void
    {
        self::assertFalse(Webhooks::verifySignature($this->payload(), '', self::SECRET));
    }

    public function testAnEmptySecretFails(): void
    {
        $payload = $this->payload();

        self::assertFalse(Webhooks::verifySignature($payload, $this->sign($payload), ''));
    }

    public function testAReEncodedBodyDoesNotMatch(): void
    {
        $payload = $this->payload();
        $signature = $this->sign($payload);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $reEncoded = json_encode(array_reverse($decoded), JSON_THROW_ON_ERROR);

        self::assertFalse(Webhooks::verifySignature($reEncoded, $signature, self::SECRET));
    }

    public function testVerifyReturnsTheParsedEvent(): void
    {
        $payload = $this->payload();

        $event = Webhooks::verify($payload, $this->sign($payload), self::SECRET, now: $this->sentAt());

        self::assertSame('ESCALATION_CREATED', $event['event']);
        self::assertSame('user_12345', $event['identityId']);
    }

    public function testABadSignatureThrows(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/signature/');

        Webhooks::verify($this->payload(), 'deadbeef', self::SECRET, now: $this->sentAt());
    }

    public function testAVerificationFailureIsCatchableAsTheBaseClass(): void
    {
        $this->expectException(DregsException::class);

        Webhooks::verify($this->payload(), 'deadbeef', self::SECRET, now: $this->sentAt());
    }

    public function testABodyThatIsNotJsonThrows(): void
    {
        $payload = 'not json at all';

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/JSON/');

        Webhooks::verify($payload, $this->sign($payload), self::SECRET);
    }

    public function testAJsonArrayBodyThrows(): void
    {
        $payload = '[1, 2, 3]';

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/JSON object/');

        Webhooks::verify($payload, $this->sign($payload), self::SECRET);
    }

    public function testAStalePayloadIsRefusedAsAReplay(): void
    {
        $payload = $this->payload();

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/replay/');

        Webhooks::verify(
            $payload,
            $this->sign($payload),
            self::SECRET,
            now: $this->moment('2026-09-21T15:22:09Z')
        );
    }

    public function testAPayloadFromTheFutureIsAlsoRefused(): void
    {
        $payload = $this->payload();

        $this->expectException(WebhookVerificationException::class);

        Webhooks::verify(
            $payload,
            $this->sign($payload),
            self::SECRET,
            now: $this->moment('2026-09-21T13:22:09Z')
        );
    }

    public function testAPayloadInsideTheToleranceIsAccepted(): void
    {
        $payload = $this->payload();

        $event = Webhooks::verify(
            $payload,
            $this->sign($payload),
            self::SECRET,
            now: $this->moment('2026-09-21T14:24:09Z')
        );

        self::assertSame('ESCALATION_CREATED', $event['event']);
    }

    public function testTheFreshnessCheckCanBeWaived(): void
    {
        $payload = $this->payload();

        $event = Webhooks::verify(
            $payload,
            $this->sign($payload),
            self::SECRET,
            tolerance: null,
            now: $this->moment('2026-10-21T14:22:09Z')
        );

        self::assertSame('ESCALATION_CREATED', $event['event']);
    }

    public function testAMissingTimestampThrowsUnlessTheCheckIsWaived(): void
    {
        $payload = json_encode(['event' => 'ESCALATION_CREATED'], JSON_THROW_ON_ERROR);

        $waived = Webhooks::verify($payload, $this->sign($payload), self::SECRET, tolerance: null);

        self::assertSame('ESCALATION_CREATED', $waived['event']);

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/no timestamp/');

        Webhooks::verify($payload, $this->sign($payload), self::SECRET);
    }

    public function testAnUnreadableTimestampThrows(): void
    {
        $payload = $this->payload(['timestamp' => 'the day before yesterday']);

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/unreadable/');

        Webhooks::verify($payload, $this->sign($payload), self::SECRET);
    }

    public function testATimestampCarryingAnOffsetIsCompared(): void
    {
        $payload = $this->payload(['timestamp' => '2026-09-21T16:22:09+02:00']);

        $event = Webhooks::verify($payload, $this->sign($payload), self::SECRET, now: $this->sentAt());

        self::assertSame('ESCALATION_CREATED', $event['event']);
    }

    public function testAnEmptyObjectBodyIsAcceptedWhenFreshnessIsWaived(): void
    {
        $payload = '{}';

        self::assertSame([], Webhooks::verify($payload, $this->sign($payload), self::SECRET, tolerance: null));
    }

    public function testTheHeaderNamesAreTheOnesDregsSends(): void
    {
        self::assertSame('X-Dregs-Signature', Webhooks::SIGNATURE_HEADER);
        self::assertSame('X-Dregs-Timestamp', Webhooks::TIMESTAMP_HEADER);
        self::assertSame('X-Dregs-Event', Webhooks::EVENT_HEADER);
        self::assertSame(300, Webhooks::DEFAULT_TOLERANCE_SECONDS);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function payload(array $overrides = []): string
    {
        return json_encode([
            'event' => 'ESCALATION_CREATED',
            'timestamp' => self::SENT_AT,
            'identityId' => 'user_12345',
            ...$overrides,
        ], JSON_THROW_ON_ERROR);
    }

    private function sign(string $payload, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private function sentAt(): DateTimeImmutable
    {
        return $this->moment(self::SENT_AT);
    }

    private function moment(string $iso8601): DateTimeImmutable
    {
        return new DateTimeImmutable($iso8601, new DateTimeZone('UTC'));
    }
}
