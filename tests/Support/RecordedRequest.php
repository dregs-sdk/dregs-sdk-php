<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * One request a {@see FakeTransport} was asked to send, kept for the test to inspect.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
    ) {
    }

    /**
     * The request body, decoded, failing the test when there was none or it was not JSON.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        Assert::assertNotNull($this->body, 'The request carried no body.');

        $decoded = json_decode((string) $this->body, true);

        Assert::assertIsArray($decoded, 'The request body was not a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A header's value, or null when it was not sent.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $sent => $value) {
            if (strcasecmp($sent, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The request's path and query, with the base URL stripped off.
     */
    public function pathAfter(string $baseUrl): string
    {
        return str_starts_with($this->url, $baseUrl)
            ? substr($this->url, strlen($baseUrl))
            : $this->url;
    }
}
