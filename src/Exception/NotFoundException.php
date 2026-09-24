<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 404. No such identity, or no analysis has been run for it yet.
 *
 * `identities.analysis()` throws this until the first cycle has finished, which is the normal
 * state of a brand-new identity rather than a problem.
 */
class NotFoundException extends ApiException
{
}
