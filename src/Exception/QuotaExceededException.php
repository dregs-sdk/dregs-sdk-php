<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 402. The account is over its monthly event limit and ingestion is refused.
 *
 * Events are not queued while an account is over its limit, so the caller decides whether to
 * drop the event or hold it somewhere of their own. The limit resets with the billing period;
 * upgrading the plan clears it immediately.
 */
class QuotaExceededException extends ApiException
{
}
