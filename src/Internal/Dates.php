<?php

declare(strict_types=1);

namespace Dregs\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * ISO-8601 parsing and formatting, shared by the models and the webhook verifier.
 *
 * Parsing is deliberately narrow. `new DateTimeImmutable($value)` will happily read "tomorrow"
 * and "+3 weeks", which is the wrong kind of generous for something reading a machine-written
 * field: a garbled timestamp should come back as null, not as a plausible-looking moment.
 *
 * @internal This class is not part of the SDK's public surface and may change in a patch
 *           release.
 */
final class Dates
{
    /**
     * ISO-8601 to the precision the API emits: a date, a time, optional fractional seconds,
     * and an optional zone offset.
     */
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d{1,9})?([Zz]|[+-]\d{2}:?\d{2})?$/';

    private function __construct()
    {
    }

    /**
     * Parses an ISO-8601 timestamp, treating a value with no zone as UTC.
     *
     * @param mixed $value The raw field, which may be absent or any JSON type.
     *
     * @return DateTimeImmutable|null The parsed moment, or null for anything unreadable.
     */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::ISO_8601, $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Formats a moment the way the API's `Instant` parser expects: UTC, with a `Z` suffix.
     *
     * Sub-second precision is kept when the value carries any, and left off when it does not,
     * so the common case reads as the plain `2026-09-21T14:22:09Z` the manual shows.
     */
    public static function format(DateTimeInterface $value): string
    {
        $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));

        $format = $utc->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z';

        return $utc->format($format);
    }
}
