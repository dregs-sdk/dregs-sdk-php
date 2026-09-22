<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use Dregs\Http\Response;
use Dregs\Http\Transport;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A transport that answers from a script instead of the network.
 *
 * Queue the responses a test wants, in order, and the requests the client made are all
 * recorded for inspection afterwards. A queued `Throwable` is thrown instead of answered,
 * which is how connection failures and timeouts are simulated.
 */
final class FakeTransport implements Transport
{
    /** @var list<RecordedRequest> */
    public array $requests = [];

    /** @var list<Response|Throwable> */
    private array $queue = [];

    private ?Response $fallback = null;

    /**
     * Answers every request with this response, however many there are.
     */
    public static function always(Response $response): self
    {
        $transport = new self();
        $transport->fallback = $response;

        return $transport;
    }

    /**
     * Answers with a JSON body and a 200, for the common case.
     *
     * @param array<string, mixed>|list<mixed> $body
     */
    public static function alwaysJson(array $body, int $status = 200): self
    {
        return self::always(Responses::json($status, $body));
    }

    /**
     * Queues answers, used in order. A Throwable is thrown rather than returned.
     */
    public function queue(Response|Throwable ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->queue[] = $answer;
        }

        return $this;
    }

    /**
     * How many requests the client has made.
     */
    public function callCount(): int
    {
        return count($this->requests);
    }

    /**
     * The request at `$index`, failing the test when there was none.
     */
    public function request(int $index = 0): RecordedRequest
    {
        Assert::assertArrayHasKey($index, $this->requests, "No request was made at index {$index}.");

        return $this->requests[$index];
    }

    /**
     * The most recent request, failing the test when none was made.
     */
    public function lastRequest(): RecordedRequest
    {
        Assert::assertNotSame([], $this->requests, 'No request was made.');

        return $this->requests[count($this->requests) - 1];
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = new RecordedRequest($method, $url, $headers, $body);

        $answer = array_shift($this->queue) ?? $this->fallback;

        if ($answer === null) {
            Assert::fail("The fake transport had no answer queued for {$method} {$url}.");
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
