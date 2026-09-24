<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * An incoming webhook did not verify against the channel's signing secret.
 *
 * Answer the delivery with a 400 and do not act on the payload. The usual cause is verifying
 * a re-serialized body instead of the raw request bytes.
 */
class WebhookVerificationException extends DregsException
{
}
