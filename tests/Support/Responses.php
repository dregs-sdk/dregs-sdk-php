<?php

declare(strict_types=1);

namespace Dregs\Tests\Support;

use Dregs\Http\Response;

/**
 * Shorthand for building the responses a test wants a {@see FakeTransport} to give back.
 */
final class Responses
{
    private function __construct()
    {
    }

    /**
     * A response with a JSON body.
     *
     * @param array<string, mixed>|list<mixed> $body
     * @param array<string, string>            $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        $encoded = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR);

        return new Response($status, self::normalise($headers), $encoded);
    }

    /**
     * A response with a JSON array body, for the endpoints that return lists.
     *
     * @param list<mixed>           $body
     * @param array<string, string> $headers
     */
    public static function jsonList(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, self::normalise($headers), json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * A response with no body at all, as `POST .../actions/analyze` sends.
     *
     * @param array<string, string> $headers
     */
    public static function empty(int $status, array $headers = []): Response
    {
        return new Response($status, self::normalise($headers), '');
    }

    /**
     * A response whose body is not JSON, such as a gateway's HTML error page.
     *
     * @param array<string, string> $headers
     */
    public static function text(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, self::normalise($headers), $body);
    }

    /**
     * The error body Spring sends, which is what a real failure looks like.
     *
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $message, array $headers = []): Response
    {
        return self::json($status, [
            'timestamp' => '2026-09-21T14:22:09Z',
            'status' => $status,
            'error' => 'Error',
            'message' => $message,
        ], $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function normalise(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        return $normalised;
    }
}
