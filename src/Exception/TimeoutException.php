<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * The request was still outstanding when the configured timeout elapsed.
 *
 * Unlike a connection failure, a timeout leaves the outcome genuinely unknown: the event may
 * have been recorded before the answer was lost. This is what the idempotency id is for. Pass
 * your own `eventId` to `track()` and a repeat of the call cannot double-count.
 */
class TimeoutException extends ConnectionException
{
}
