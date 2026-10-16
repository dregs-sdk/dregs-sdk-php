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
        if ($this->body === null) {
            Assert::fail('The request carried no body.');
        }

        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            Assert::fail('The request body was not a JSON object.');
        }

        $fields = [];

        foreach ($decoded as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /**
     * One field of the JSON body, failing the test when it is absent or not a string.
     */
    public function stringField(string $key): string
    {
        $value = $this->json()[$key] ?? null;

        if (!is_string($value)) {
            Assert::fail("The request body field '{$key}' was not a string.");
        }

        return $value;
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
     * The request's path, with the base URL and any query string stripped off.
     */
    public function pathAfter(string $baseUrl): string
    {
        $path = str_starts_with($this->url, $baseUrl)
            ? substr($this->url, strlen($baseUrl))
            : $this->url;

        $mark = strpos($path, '?');

        return $mark === false ? $path : substr($path, 0, $mark);
    }

    /**
     * The query parameters, decoded, or an empty array when there were none.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $raw = parse_url($this->url, PHP_URL_QUERY);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        parse_str($raw, $decoded);

        $parameters = [];

        foreach ($decoded as $name => $value) {
            if (is_string($value)) {
                $parameters[(string) $name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The raw query string, exactly as sent, or an empty string when there was none.
     */
    public function rawQuery(): string
    {
        $raw = parse_url($this->url, PHP_URL_QUERY);

        return is_string($raw) ? $raw : '';
    }
}
