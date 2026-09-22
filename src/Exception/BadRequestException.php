<?php

declare(strict_types=1);

namespace Dregs\Exception;

/**
 * 400. The request was malformed or missing something Dregs requires.
 *
 * For event ingestion this most often means the event carried neither an identity nor a
 * device, or the body failed validation. Retrying it unchanged will fail the same way.
 */
class BadRequestException extends ApiException
{
}
