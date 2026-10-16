<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Arguments;
use Dregs\Internal\Lister;
use Dregs\Internal\Payload;
use Dregs\Model\Device;
use Dregs\Model\Page;
use Generator;
use InvalidArgumentException;

/**
 * The `$client->devices` namespace: the browsers and devices Dregs has seen.
 */
final class Devices
{
    /**
     * @param Client $client The client whose credentials and base URL these calls use.
     *
     * @internal Built by {@see Client}. Reach it as `$client->devices`.
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Returns one page of devices, most recently seen first.
     *
     * @param string|null $identity Only devices this identity was seen on.
     * @param string|null $term     Free text matched against fingerprints, addresses, places,
     *                              and user agents.
     * @param int         $page     The page to return, counting from 0.
     * @param int|null    $pageSize Items per page, at most 100.
     * @param string|null $sort     The field to sort on, such as "lastSeenAt" or "country".
     * @param string|null $order    "asc" or "desc".
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException A negative page, or an order other than "asc" or
     *                                  "desc".
     *
     * @return Page<Device>
     */
    public function list(
        ?string $identity = null,
        ?string $term = null,
        int $page = 0,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Page {
        return $this->lister($identity, $term, $pageSize, $sort, $order)->page($page);
    }

    /**
     * Yields every device that matches, fetching pages as it goes. See {@see self::list()}.
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An order other than "asc" or "desc".
     *
     * @return Generator<int, Device, mixed, void>
     */
    public function listAll(
        ?string $identity = null,
        ?string $term = null,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Generator {
        return $this->lister($identity, $term, $pageSize, $sort, $order)->walk();
    }

    /**
     * Returns the device with this fingerprint.
     *
     * @param string $fingerprint The device's fingerprint.
     *
     * @throws NotFoundException        No device has this fingerprint.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The fingerprint was empty.
     */
    public function get(string $fingerprint): Device
    {
        return Device::fromApi(Payload::asMap($this->client->request('GET', self::path($fingerprint))));
    }

    /**
     * Sets whether the device is disregarded, and returns it.
     *
     * A disregarded device, such as a shared office machine, no longer links the identities
     * seen on it.
     *
     * ```php
     * $client->devices->update('a1b2c3d4e5', disregarded: true);
     * ```
     *
     * @param string $fingerprint The device's fingerprint.
     * @param bool   $disregarded Whether to disregard it.
     *
     * @throws NotFoundException        No device has this fingerprint.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The fingerprint was empty.
     */
    public function update(string $fingerprint, bool $disregarded): Device
    {
        $payload = $this->client->request('PATCH', self::path($fingerprint), ['disregarded' => $disregarded]);

        return Device::fromApi(Payload::asMap($payload));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return Lister<Device>
     */
    private function lister(?string $identity, ?string $term, ?int $pageSize, ?string $sort, ?string $order): Lister
    {
        $filters = ['identity' => $identity, 'term' => $term];

        return new Lister($this->client, '/devices', Device::fromApi(...), $filters, $pageSize, $sort, $order);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function path(string $fingerprint): string
    {
        return '/devices/' . Arguments::segment($fingerprint, 'A device fingerprint');
    }
}
