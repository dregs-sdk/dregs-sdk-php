<?php

declare(strict_types=1);

namespace Dregs\Exception;

use RuntimeException;

/**
 * Base class for everything this library throws.
 *
 * A caller that only wants a coarse "the Dregs call failed" branch can catch this one class.
 * Exceptions that came back from the API derive from {@see ApiException} and carry the HTTP
 * status and the parsed body; failures that never reached the API (DNS, a refused connection,
 * a timeout) derive from {@see ConnectionException} instead.
 *
 * Bad arguments are the exception to the rule: passing an empty identity or a reserved event
 * id throws PHP's own `InvalidArgumentException`, because those are programming mistakes to
 * fix rather than runtime conditions to handle.
 */
class DregsException extends RuntimeException
{
}
