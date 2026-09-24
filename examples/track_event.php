<?php

/**
 * Send a backend event to Dregs.
 *
 * Run it with your credential's secret key in the environment:
 *
 *     DREGS_SECRET_KEY=sk_... php examples/track_event.php
 */

declare(strict_types=1);

use Dregs\Client;
use Dregs\Exception\DregsException;
use Dregs\Exception\QuotaExceededException;
use Dregs\Exception\RateLimitException;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client();

try {
    $result = $client->track(
        'user.signup',
        identity: 'user_12345',
        // Attributes of the event.
        data: ['plan' => 'pro', 'referrer' => 'partner-x'],
        // Attributes of the user. The analyzers lean on these, so send what you have.
        identityData: [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'username' => 'ada',
        ],
        // Your own id for the event makes ingestion idempotent: running this script twice is
        // a no-op rather than a second signup.
        eventId: 'signup-991',
    );
} catch (QuotaExceededException) {
    exit('Over the monthly event limit. The event was not recorded.' . PHP_EOL);
} catch (RateLimitException $exception) {
    exit('Rate limited. Retry after ' . ($exception->retryAfter ?? 'a moment') . '.' . PHP_EOL);
} catch (DregsException $exception) {
    exit('Could not reach Dregs: ' . $exception->getMessage() . PHP_EOL);
}

if ($result->isAccepted()) {
    echo "Recorded event {$result->id}.", PHP_EOL;
} else {
    // Uncommon, and worth a log line: accepted without an event being recorded.
    echo "The event was not recorded (status {$result->status}).", PHP_EOL;
}
