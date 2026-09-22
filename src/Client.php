<?php

declare(strict_types=1);

namespace Dregs;

use DateTimeInterface;
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
use Dregs\Http\CurlTransport;
use Dregs\Http\Psr18Transport;
use Dregs\Http\Response;
use Dregs\Http\Transport;
use Dregs\Internal\Dates;
use Dregs\Internal\Env;
use Dregs\Internal\Payload;
use Dregs\Model\TrackResult;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Random\RandomException;

/**
 * A Dregs client.
 *
 * The secret key comes from the `DREGS_SECRET_KEY` environment variable unless you pass one.
 * Find it under **Settings -> Credentials** in the dashboard; it is the key starting `sk_`,
 * not the `pk_` public key the browser tracker uses.
 *
 * ```php
 * $client = new Dregs\Client();
 *
 * $client->track('user.signup', identity: 'user_12345', data: ['plan' => 'pro']);
 *
 * $scores = $client->identities->scores('user_12345');
 * ```
 *
 * The client is stateless beyond its configuration, so build one at startup, register it in
 * your container, and reuse it. Nothing here holds a connection open between calls.
 *
 * This client is synchronous: every call blocks until Dregs answers or the retries run out.
 * Tracking an event is not something a user's request should wait on, so push `track()` calls
 * onto a queue — Laravel's `dispatch()`, Symfony Messenger, or whatever your application
 * already uses — rather than making a signup slower by the round trip.
 */
class Client
{
    /** This SDK's version, as it appears in the `User-Agent`. */
    public const VERSION = '0.1.0';

    /** Where requests go when neither an argument nor the environment says otherwise. */
    public const DEFAULT_BASE_URL = 'https://dregs.com/api';

    /** Seconds before a request is abandoned, by default. */
    public const DEFAULT_TIMEOUT = 10.0;

    /** How many times a failed request is retried, by default. */
    public const DEFAULT_MAX_RETRIES = 2;

    /** The environment variable the secret key is read from. */
    public const SECRET_KEY_ENV = 'DREGS_SECRET_KEY';

    /** The environment variable the base URL is read from. */
    public const BASE_URL_ENV = 'DREGS_BASE_URL';

    /** The `source` recorded on events this SDK sends. */
    public const SOURCE = 'php-sdk';

    /** The longest an event id may be, as the ingestion endpoint enforces it. */
    public const MAX_EVENT_ID_LENGTH = 64;

    /** Event ids beginning with this are Dregs's own and are refused on ingestion. */
    public const RESERVED_EVENT_ID_PREFIX = 'dregs-';

    /**
     * Statuses worth another attempt. 429 and 5xx are transient by definition; 408 turns up
     * in front of some proxies.
     *
     * @var list<int>
     */
    public const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];

    /** The longest this client will wait between attempts, however large `Retry-After` is. */
    private const MAX_BACKOFF_SECONDS = 60.0;

    /** The ceiling on the exponential part of the backoff, before jitter. */
    private const MAX_JITTER_WINDOW_SECONDS = 8.0;

    /** An older API build reported the ingestion rate limit in the body of a 200. */
    private const STATUS_RATE_LIMITED = 'rate_limited';

    /** An older API build reported the monthly event limit in the body of a 200. */
    private const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    /** Read identities, their scores, and their analysis. */
    public readonly Identities $identities;

    /** The API root every request is built against, with any trailing slash removed. */
    public readonly string $baseUrl;

    /** How many times a failed request is retried before the exception is thrown. */
    public readonly int $maxRetries;

    private readonly string $secretKey;

    private readonly Transport $transport;

    /**
     * @param string|null                     $secretKey      Your credential's secret key.
     *                                                        Defaults to `$DREGS_SECRET_KEY`.
     * @param string|null                     $baseUrl        The API root. Defaults to
     *                                                        `$DREGS_BASE_URL`, then
     *                                                        `https://dregs.com/api`.
     * @param float                           $timeout        Seconds before a request is
     *                                                        abandoned. Ignored when you
     *                                                        supply your own transport, which
     *                                                        carries its own.
     * @param int                             $maxRetries     How many times to retry a failed
     *                                                        request. Retries cover
     *                                                        connection failures, timeouts,
     *                                                        429s, and 5xx, with exponential
     *                                                        backoff and jitter; `Retry-After`
     *                                                        wins when the server sends one.
     *                                                        Pass 0 to handle it yourself.
     * @param Transport|ClientInterface|null  $httpClient     A {@see Transport}, or a PSR-18
     *                                                        client to wrap in one. Leave it
     *                                                        null for the built-in cURL
     *                                                        transport, which needs nothing
     *                                                        installed.
     * @param RequestFactoryInterface|null    $requestFactory A PSR-17 request factory, needed
     *                                                        only alongside a PSR-18 client
     *                                                        that is not its own factory.
     * @param StreamFactoryInterface|null     $streamFactory  A PSR-17 stream factory, under
     *                                                        the same rule.
     *
     * @throws InvalidArgumentException No secret key, a `pk_` public key, or a negative retry
     *                                  count.
     */
    public function __construct(
        ?string $secretKey = null,
        ?string $baseUrl = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        Transport|ClientInterface|null $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $resolvedKey = $secretKey ?? Env::get(self::SECRET_KEY_ENV);

        if ($resolvedKey === null || $resolvedKey === '') {
            throw new InvalidArgumentException(
                'No Dregs secret key. Pass one to the constructor or set the '
                . self::SECRET_KEY_ENV . ' environment variable. You will find your '
                . "credential's secret key under Settings -> Credentials in the Dregs "
                . 'dashboard.'
            );
        }

        if (str_starts_with($resolvedKey, 'pk_')) {
            throw new InvalidArgumentException(
                'That is a public key. The public key is for the browser tracker and cannot '
                . 'read identities or scores; this SDK needs the secret key from the same '
                . "credential, which starts with 'sk_'."
            );
        }

        if ($maxRetries < 0) {
            throw new InvalidArgumentException('maxRetries cannot be negative.');
        }

        $resolvedUrl = $baseUrl ?? Env::get(self::BASE_URL_ENV) ?? self::DEFAULT_BASE_URL;

        $this->secretKey = $resolvedKey;
        $this->baseUrl = rtrim($resolvedUrl, '/');
        $this->maxRetries = $maxRetries;

        if ($httpClient === null) {
            $this->transport = new CurlTransport($timeout);
        } elseif ($httpClient instanceof Transport) {
            $this->transport = $httpClient;
        } else {
            $this->transport = new Psr18Transport($httpClient, $requestFactory, $streamFactory);
        }

        $this->identities = new Identities($this);
    }

    /**
     * Records a backend event against an identity.
     *
     * ```php
     * $client->track(
     *     'user.signup',
     *     identity: 'user_12345',
     *     data: ['plan' => 'pro', 'referrer' => 'partner-x'],
     *     identityData: ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'],
     * );
     * ```
     *
     * @param string                    $eventType    Your name for the event, such as
     *                                                "user.signup". Map it to one of Dregs's
     *                                                canonical types under **Settings ->
     *                                                Mappings** so the analyzers know what it
     *                                                means.
     * @param string                    $identity     Your own id for the user. This is the
     *                                                same id you pass to `dregs.identify()`
     *                                                in the browser tracker, and the one you
     *                                                look scores up by. It is required: a
     *                                                server-side event carries no device
     *                                                signature, so the identity is the only
     *                                                thing tying it to a user.
     * @param array<string, mixed>|null $data         Attributes of the event itself.
     * @param array<string, mixed>|null $identityData Attributes of the *user*, such as email,
     *                                                name, or username. Dregs merges these
     *                                                into the identity, and the analyzers
     *                                                lean on them heavily, so send them
     *                                                whenever you have them. Flat keys work
     *                                                best; name them as your application
     *                                                already does and map them under
     *                                                **Settings -> Mappings**.
     * @param string|null               $eventId      Your own id for the event, which makes
     *                                                ingestion idempotent: reposting the same
     *                                                id returns the original event instead of
     *                                                recording a second one. Pass the id your
     *                                                application already has — the row id of
     *                                                the record that triggered the event,
     *                                                say. When you omit it the SDK generates
     *                                                one, which is what makes its own retries
     *                                                safe. At most 64 characters, and it
     *                                                cannot start with "dregs-".
     * @param DateTimeInterface|null    $timestamp    When the event happened, if not now. Any
     *                                                timezone; it is sent as UTC.
     * @param string|null               $source       A label for where the event came from.
     *                                                Defaults to "php-sdk".
     *
     * @throws QuotaExceededException   The account is over its monthly event limit.
     * @throws RateLimitException       The credential is ingesting too fast.
     * @throws AuthenticationException  The secret key was not recognized.
     * @throws BadRequestException      The event was malformed.
     * @throws TimeoutException         Every attempt timed out.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An argument was empty, reserved, or too long.
     *
     * @return TrackResult The outcome. Check `isAccepted()` to tell a recorded event from one
     *                     of the rejections Dregs answers quietly.
     */
    public function track(
        string $eventType,
        string $identity,
        ?array $data = null,
        ?array $identityData = null,
        ?string $eventId = null,
        ?DateTimeInterface $timestamp = null,
        ?string $source = null,
    ): TrackResult {
        $body = $this->trackBody(
            $eventType,
            $identity,
            $data,
            $identityData,
            $eventId,
            $timestamp,
            $source,
        );

        return TrackResult::fromApi(Payload::asMap($this->request('POST', '/events', $body)));
    }

    /**
     * Sends one request, retrying what is worth retrying, and returns the decoded body.
     *
     * @param string                    $method The HTTP method.
     * @param string                    $path   The path below the base URL, already escaped.
     * @param array<string, mixed>|null $body   The request body, or null for a request
     *                                          without one.
     *
     * @throws ApiException        Dregs answered with an error status.
     * @throws TimeoutException    Every attempt timed out.
     * @throws ConnectionException Dregs could not be reached.
     *
     * @return mixed The decoded JSON body, or null when the response had none.
     *
     * @internal Called by {@see Identities}. Not part of the SDK's public surface.
     */
    public function request(string $method, string $path, ?array $body = null): mixed
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $encoded = $body === null ? null : self::encode($body);
        $headers = $this->headers($encoded !== null);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $encoded);
            } catch (ConnectionException $exception) {
                if (!$this->shouldRetry($attempt, null)) {
                    throw $exception;
                }

                $this->sleepFor($this->backoffSeconds($attempt, null));
                ++$attempt;

                continue;
            }

            try {
                return self::processResponse($response);
            } catch (ApiException $exception) {
                if (!$this->shouldRetry($attempt, $exception->statusCode)) {
                    throw $exception;
                }

                $retryAfter = $exception instanceof RateLimitException ? $exception->retryAfter : null;

                $this->sleepFor($this->backoffSeconds($attempt, $retryAfter));
                ++$attempt;
            }
        }
    }

    /**
     * Whether another attempt is worth making.
     *
     * @param int      $attempt    How many attempts have already failed.
     * @param int|null $statusCode The status that failed, or null for a transport failure.
     */
    protected function shouldRetry(int $attempt, ?int $statusCode): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return $statusCode === null || in_array($statusCode, self::RETRY_STATUSES, true);
    }

    /**
     * Seconds to wait before the next attempt.
     *
     * `Retry-After` wins when the server sent one. Otherwise this is exponential with full
     * jitter, which keeps a fleet of workers that all hit the limit at once from retrying in
     * lockstep and hitting it again together.
     *
     * @param int        $attempt    How many attempts have already failed, from zero.
     * @param float|null $retryAfter The server's own advice, in seconds, when it gave any.
     */
    protected function backoffSeconds(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null && $retryAfter >= 0.0) {
            return min($retryAfter, self::MAX_BACKOFF_SECONDS);
        }

        $window = min(0.5 * (2 ** $attempt), self::MAX_JITTER_WINDOW_SECONDS);

        try {
            $jitter = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
        } catch (RandomException) {
            $jitter = 0.5;
        }

        return $window * $jitter;
    }

    /**
     * Waits between attempts.
     *
     * Overridable so a test can run the retry logic without actually sleeping.
     */
    protected function sleepFor(float $seconds): void
    {
        if ($seconds > 0.0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * Builds the `POST /api/events` body.
     *
     * An event id is always sent. When the caller has one of their own it is used verbatim,
     * so reposting the same event is a no-op on the Dregs side; otherwise one is generated,
     * which is what makes this client's own retries safe to perform.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $identityData
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function trackBody(
        string $eventType,
        string $identity,
        ?array $data,
        ?array $identityData,
        ?string $eventId,
        ?DateTimeInterface $timestamp,
        ?string $source,
    ): array {
        if ($eventType === '') {
            throw new InvalidArgumentException('eventType is required.');
        }

        if ($identity === '') {
            throw new InvalidArgumentException(
                'identity is required. A server-side event has no device signature, so the '
                . 'identity is the only thing tying the event to a user.'
            );
        }

        $resolvedId = $eventId ?? self::generateEventId();

        if (str_starts_with($resolvedId, self::RESERVED_EVENT_ID_PREFIX)) {
            throw new InvalidArgumentException(
                "Event ids starting with '" . self::RESERVED_EVENT_ID_PREFIX
                . "' are reserved for Dregs itself."
            );
        }

        if (strlen($resolvedId) > self::MAX_EVENT_ID_LENGTH) {
            throw new InvalidArgumentException(
                'Event ids cannot be longer than ' . self::MAX_EVENT_ID_LENGTH . ' characters.'
            );
        }

        $body = [
            'id' => $resolvedId,
            'type' => $eventType,
            'data' => (object) ($data ?? []),
            'identity' => [
                'id' => $identity,
                'data' => (object) ($identityData ?? []),
            ],
            'source' => $source ?? self::SOURCE,
        ];

        if ($timestamp !== null) {
            $body['timestamp'] = Dates::format($timestamp);
        }

        return $body;
    }

    /**
     * The headers every request carries.
     *
     * @return array<string, string>
     */
    private function headers(bool $hasBody): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
            'User-Agent' => self::userAgent(),
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /**
     * An idempotency key for an event the caller did not name.
     *
     * A random 32-character hex string: short of the 64-character ceiling, and with no
     * "dregs-" prefix, which the ingestion endpoint reserves for its own ids.
     */
    private static function generateEventId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException $exception) {
            throw new DregsException(
                'Could not generate an event id: no source of randomness is available.',
                0,
                $exception
            );
        }
    }

    /**
     * Turns a response into a decoded body, or throws the matching exception.
     *
     * @throws ApiException
     */
    private static function processResponse(Response $response): mixed
    {
        $payload = self::decode($response->body);

        if ($response->statusCode >= 400) {
            throw self::apiException($response, $payload);
        }

        // An older API build reported both of these as HTTP 200 with the outcome in the body.
        // Reading the body as well as the status keeps this SDK correct against either.
        if (is_array($payload)) {
            $status = $payload['status'] ?? null;

            if ($status === self::STATUS_RATE_LIMITED) {
                throw new RateLimitException(
                    'Ingestion rate limit exceeded for this credential.',
                    429,
                    $payload,
                    $response->header('X-Request-Id'),
                    self::retryAfter($response),
                );
            }

            if ($status === self::STATUS_QUOTA_EXCEEDED) {
                throw new QuotaExceededException(
                    'The account is over its monthly event limit.',
                    402,
                    $payload,
                    $response->header('X-Request-Id'),
                );
            }
        }

        return $payload;
    }

    /**
     * Picks the exception class for an error response and fills it in.
     */
    private static function apiException(Response $response, mixed $payload): ApiException
    {
        $description = self::describe($payload) ?? 'Request failed';
        $requestId = $response->header('X-Request-Id');
        $status = $response->statusCode;

        if ($status === 429) {
            return new RateLimitException(
                $description,
                429,
                $payload,
                $requestId,
                self::retryAfter($response),
            );
        }

        return match (true) {
            $status === 400 => new BadRequestException($description, $status, $payload, $requestId),
            $status === 401 => new AuthenticationException($description, $status, $payload, $requestId),
            $status === 402 => new QuotaExceededException($description, $status, $payload, $requestId),
            $status === 403 => new PermissionDeniedException($description, $status, $payload, $requestId),
            $status === 404 => new NotFoundException($description, $status, $payload, $requestId),
            $status >= 500 => new ServerException($description, $status, $payload, $requestId),
            default => new ApiException($description, $status, $payload, $requestId),
        };
    }

    /**
     * Finds the most human-readable message an error body carries.
     */
    private static function describe(mixed $payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach (['message', 'error', 'status'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Reads `Retry-After` as seconds.
     *
     * The header also allows an HTTP date, which is rare enough here that falling back to the
     * client's own backoff beats dragging in a date parser for it.
     */
    private static function retryAfter(Response $response): ?float
    {
        $raw = $response->header('Retry-After');

        return $raw !== null && is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidArgumentException The body holds something JSON cannot represent.
     */
    private static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The event could not be encoded as JSON: ' . $exception->getMessage()
                . '. Event and identity data must hold only values json_encode understands, '
                . 'which rules out resources and objects without a JsonSerializable.',
                0,
                $exception
            );
        }
    }

    /**
     * Decodes a response body, treating anything unreadable as no body at all.
     *
     * A gateway's HTML error page is not worth an exception of its own: the status has
     * already said what went wrong, and the caller gets a null `body` on the exception.
     */
    private static function decode(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * The `User-Agent` this SDK identifies itself with.
     */
    private static function userAgent(): string
    {
        return 'dregs-php/' . self::VERSION . ' (php ' . PHP_VERSION . ')';
    }
}
