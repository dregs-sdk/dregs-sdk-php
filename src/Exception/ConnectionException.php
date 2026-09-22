<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * The request never reached Dregs: DNS, TCP, TLS, or a dropped connection.
 *
 * The client retries these automatically, so seeing one means every attempt failed. Nothing
 * was recorded, and the call is safe to make again later.
 */
class ConnectionException extends DregsException
{
}
