<?php

/**
 * Read an identity's scores, and the observations behind them.
 *
 *     DREGS_SECRET_KEY=sk_... php examples/read_scores.php user_12345
 */

declare(strict_types=1);

use Dregs\Client;
use Dregs\Exception\NotFoundException;
use Dregs\Model\Observation;

require __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

$identityId = $arguments[1] ?? 'user_12345';

$client = new Client();

try {
    $scores = $client->identities->scores($identityId);
} catch (NotFoundException) {
    exit("Dregs has never seen {$identityId}." . PHP_EOL);
}

if ($scores->isEmpty()) {
    exit("{$identityId} has not been scored yet. Scoring runs shortly after new activity." . PHP_EOL);
}

$format = static fn (?int $score): string => $score === null ? 'not scored' : (string) $score;
$number = static fn (?float $value): string => $value === null ? 'unknown' : sprintf('%.2f', $value);

echo "Scores for {$identityId}", PHP_EOL;
echo '  Humanity:     ', $format($scores->humanity()), PHP_EOL;
echo '  Authenticity: ', $format($scores->authenticity()), PHP_EOL;
echo '  Uniqueness:   ', $format($scores->uniqueness()), PHP_EOL;
echo '  Behavior:     ', $format($scores->behavior()), PHP_EOL;

// The scores are the summary. The observations are the evidence, and they come from the
// analysis cycle rather than from the scores endpoint.
try {
    $analysis = $client->identities->analysis($identityId);
} catch (NotFoundException) {
    exit(PHP_EOL . 'No analysis cycle has finished for this identity yet.' . PHP_EOL);
}

$finishedAt = $analysis->finishedAt?->format('Y-m-d H:i') ?? 'an unknown time';

echo PHP_EOL, "Why, from the cycle of {$finishedAt} UTC:", PHP_EOL;

$observations = $analysis->observations();

// Most damning first.
usort($observations, static fn (Observation $a, Observation $b): int => ($a->value ?? 1.0) <=> ($b->value ?? 1.0));

foreach ($observations as $observation) {
    echo '  [', $observation->category?->value ?? 'UNKNOWN', '] ', $observation->label, PHP_EOL;
    echo '      ', $observation->explanation, PHP_EOL;
    echo '      value ', $number($observation->value), ', confidence ', $number($observation->confidence), PHP_EOL;
}
