<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 401. The secret key was missing, unrecognized, revoked, or expired.
 *
 * Check the key against **Settings -> Credentials** in the dashboard. A key that used to work
 * and now does not has usually been revoked.
 */
class AuthenticationException extends ApiException
{
}
