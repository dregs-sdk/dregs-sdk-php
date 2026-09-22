<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 5xx. Something went wrong inside Dregs.
 *
 * These are retried automatically, so seeing one means every attempt failed. Quote the
 * `requestId` if you report it.
 */
class ServerException extends ApiException
{
}
