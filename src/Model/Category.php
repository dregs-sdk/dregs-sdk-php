<?php

declare(strict_types=1);

namespace Dregs\Model;

/**
 * The four categories Dregs scores an identity in.
 *
 * Each is an integer from 0 (worst) to 100 (best), and each is computed from its own set of
 * analyzers. They are deliberately separate: a real person filling in a fake name is a very
 * different problem from a script filling in a real one, and averaging the two into a single
 * "risk score" would hide the distinction you need to act on.
 */
enum Category: string
{
    /** How likely it is that a person, rather than a script, is behind the account. */
    case Humanity = 'HUMANITY';

    /** How genuine the details on the account look. */
    case Authenticity = 'AUTHENTICITY';

    /** How distinct the account is from others in the same tenant. */
    case Uniqueness = 'UNIQUENESS';

    /** How ordinary the account's activity looks. */
    case Behavior = 'BEHAVIOR';

    /**
     * Reads a category out of an API response, tolerating anything it does not recognize.
     *
     * A category this SDK release predates comes back as null rather than throwing, so one
     * new value in a response does not cost you the rest of it. The unrecognized name is
     * still on the score's `raw`.
     *
     * @param mixed $value The raw `category` field.
     */
    public static function tryFromApi(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtoupper($value)) : null;
    }
}
