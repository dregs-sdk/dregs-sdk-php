<?php

declare(strict_types=1);

namespace Dregs\Model;

use DateTimeImmutable;
use Dregs\Internal\Dates;
use Dregs\Internal\Payload;

/**
 * A browser or device Dregs has seen, identified by its fingerprint.
 *
 * The network and place fields describe where the device was most recently seen.
 */
final readonly class Device
{
    /**
     * @param string|null            $fingerprint The device's fingerprint.
     * @param string|null            $ip          The address it was last seen at.
     * @param string|null            $asn         The autonomous system that address belongs to.
     * @param string|null            $asnLabel    That network's name, such as a hosting
     *                                            provider's.
     * @param string|null            $userAgent   The browser's user agent.
     * @param string|null            $city        The city, from the address.
     * @param string|null            $region      The region, from the address.
     * @param string|null            $country     The country's name, from the address.
     * @param string|null            $countryCode The country's ISO code, such as "US".
     * @param string|null            $timezone    The timezone the browser reported.
     * @param float|null             $latitude    The address's approximate latitude.
     * @param float|null             $longitude   The address's approximate longitude.
     * @param bool                   $disregarded Whether the device is disregarded, so that
     *                                            it no longer links the identities seen on it.
     * @param DateTimeImmutable|null $firstSeenAt When Dregs first saw the device.
     * @param DateTimeImmutable|null $lastSeenAt  When Dregs last saw it.
     * @param array<string, mixed>   $raw         The response body as received.
     */
    public function __construct(
        public ?string $fingerprint,
        public ?string $ip = null,
        public ?string $asn = null,
        public ?string $asnLabel = null,
        public ?string $userAgent = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $countryCode = null,
        public ?string $timezone = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public bool $disregarded = false,
        public ?DateTimeImmutable $firstSeenAt = null,
        public ?DateTimeImmutable $lastSeenAt = null,
        public array $raw = [],
    ) {
    }

    /**
     * Builds a device from a decoded `GET /api/devices/{fingerprint}` response, or from one
     * item of a list.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        return new self(
            Payload::string($payload, 'fingerprint'),
            Payload::string($payload, 'ip'),
            Payload::id($payload, 'asn'),
            Payload::string($payload, 'asnLabel'),
            Payload::string($payload, 'userAgent'),
            Payload::string($payload, 'city'),
            Payload::string($payload, 'region'),
            Payload::string($payload, 'country'),
            Payload::string($payload, 'countryCode'),
            Payload::string($payload, 'timezone'),
            Payload::float($payload, 'latitude'),
            Payload::float($payload, 'longitude'),
            Payload::bool($payload, 'disregarded'),
            Dates::parse($payload['firstSeenAt'] ?? null),
            Dates::parse($payload['lastSeenAt'] ?? null),
            $payload,
        );
    }
}
