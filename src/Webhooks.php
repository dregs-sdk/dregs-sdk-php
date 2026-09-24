<?php

declare(strict_types=1);

namespace Dregs;

use DateTimeImmutable;
use DateTimeZone;
use Dregs\Exception\WebhookVerificationException;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;
use JsonException;

/**
 * Verifying webhooks Dregs sends you.
 *
 * Dregs signs every webhook with the channel's signing secret: `X-Dregs-Signature` is the
 * hex-encoded HMAC-SHA256 of the raw request body. Verify it before you act on the payload.
 *
 * **Verify the raw bytes.** Not a decoded array you re-encoded, not a framework's parsed
 * input — the body exactly as it arrived. `json_encode(json_decode($body, true))` is a
 * different string: key order, whitespace, and numeric formatting all shift, and the HMAC
 * will not match. In Laravel that means `$request->getContent()`, in Symfony
 * `$request->getContent()`, and in plain PHP `file_get_contents('php://input')`.
 *
 * ```php
 * use Dregs\Exception\WebhookVerificationException;
 * use Dregs\Webhooks;
 *
 * try {
 *     $event = Webhooks::verify(
 *         $request->getContent(),
 *         $request->headers->get(Webhooks::SIGNATURE_HEADER) ?? '',
 *         $_ENV['DREGS_WEBHOOK_SECRET'],
 *     );
 * } catch (WebhookVerificationException) {
 *     return new Response(status: 400);
 * }
 *
 * handle($event);
 * ```
 *
 * The signing secret is shown once, when you create the webhook channel. It is not your API
 * secret key: one authenticates you to Dregs, the other proves a payload came from Dregs.
 * Rotate them separately.
 */
final class Webhooks
{
    /** The header carrying the hex-encoded HMAC-SHA256 of the request body. */
    public const SIGNATURE_HEADER = 'X-Dregs-Signature';

    /** The header carrying the delivery's timestamp. */
    public const TIMESTAMP_HEADER = 'X-Dregs-Timestamp';

    /** The header naming the event type, for routing before you parse the body. */
    public const EVENT_HEADER = 'X-Dregs-Event';

    /** How far out of date a webhook's timestamp may be before {@see self::verify()} refuses it. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * Returns the hex-encoded HMAC-SHA256 of `$payload` under `$secret`.
     *
     * Exposed mostly so tests can sign a body the way Dregs would. Verification should go
     * through {@see self::verify()} or {@see self::verifySignature()}, which compare in
     * constant time.
     *
     * @param string $payload The raw request body.
     * @param string $secret  The channel's signing secret.
     */
    public static function computeSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Returns whether `$signature` matches `$payload`.
     *
     * The comparison runs in constant time, so a wrong signature cannot be narrowed down by
     * timing the answer. Prefer {@see self::verify()}, which also rejects replays and hands
     * back the parsed event; reach for this one only when the boolean is what you want.
     *
     * @param string $payload   The raw request body, exactly as received.
     * @param string $signature The `X-Dregs-Signature` header. Surrounding whitespace is
     *                          tolerated.
     * @param string $secret    The channel's signing secret.
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $signature = trim($signature);

        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(self::computeSignature($payload, $secret), $signature);
    }

    /**
     * Verifies a webhook and returns its parsed body.
     *
     * ```php
     * $event = Webhooks::verify($rawBody, $signatureHeader, $signingSecret);
     *
     * match ($event['event'] ?? null) {
     *     'ESCALATION_CREATED' => $this->openTicket($event),
     *     default => null,
     * };
     * ```
     *
     * @param string                 $payload   The raw request body, exactly as received.
     *                                          Not a re-encoded array.
     * @param string                 $signature The `X-Dregs-Signature` header.
     * @param string                 $secret    The channel's signing secret.
     * @param int|null               $tolerance How many seconds out of date the payload's own
     *                                          `timestamp` may be before it is treated as a
     *                                          replay. Pass null to skip the check, which you
     *                                          should only do if you are deduplicating on the
     *                                          event id yourself. The timestamp is inside the
     *                                          signed body, so an attacker cannot alter it
     *                                          without breaking the signature.
     * @param DateTimeImmutable|null $now       The current time. For tests; leave it null.
     *
     * @throws WebhookVerificationException The signature did not match, the body was not a
     *                                      JSON object, or the payload is older than
     *                                      `$tolerance`. Answer the delivery with a 400 and
     *                                      do not act on it.
     *
     * @return array<string, mixed> The parsed webhook body: `event`, `timestamp`, and the
     *                              payload for that event.
     */
    public static function verify(
        string $payload,
        string $signature,
        string $secret,
        ?int $tolerance = self::DEFAULT_TOLERANCE_SECONDS,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!self::verifySignature($payload, $signature, $secret)) {
            throw new WebhookVerificationException(
                'The webhook signature did not match. Check that you are verifying the raw '
                . 'request body rather than a re-encoded copy, and that the signing secret '
                . 'belongs to the channel that sent this delivery.'
            );
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WebhookVerificationException(
                'The webhook body was not valid JSON: ' . $exception->getMessage() . '.',
                0,
                $exception
            );
        }

        // An empty object decodes to an empty array, which is indistinguishable from an empty
        // JSON array, so only a non-empty list is refused outright.
        if (!is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new WebhookVerificationException('The webhook body was not a JSON object.');
        }

        $fields = Payload::asMap($event);

        if ($tolerance !== null) {
            self::checkFreshness($fields, $tolerance, $now);
        }

        return $fields;
    }

    /**
     * Refuses a payload whose own timestamp is too far from now, in either direction.
     *
     * Both directions matter: a replayed delivery is stale, and a clock far ahead of yours is
     * a sign that something is wrong with the delivery rather than something to trust.
     *
     * @param array<string, mixed> $event
     *
     * @throws WebhookVerificationException
     */
    private static function checkFreshness(array $event, int $tolerance, ?DateTimeImmutable $now): void
    {
        $raw = $event['timestamp'] ?? null;

        if (!is_string($raw) || $raw === '') {
            throw new WebhookVerificationException(
                'The webhook carried no timestamp, so it cannot be checked for replay. Pass '
                . 'tolerance: null if you are deduplicating deliveries some other way.'
            );
        }

        $sent = Dates::parse($raw);

        if ($sent === null) {
            throw new WebhookVerificationException("The webhook timestamp was unreadable: '{$raw}'.");
        }

        $reference = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $age = abs($reference->getTimestamp() - $sent->getTimestamp());

        if ($age > $tolerance) {
            throw new WebhookVerificationException(
                "The webhook timestamp is {$age}s away from now, beyond the {$tolerance}s "
                . 'tolerance. Treating it as a replay.'
            );
        }
    }
}
