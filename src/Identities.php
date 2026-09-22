<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Payload;
use Dregs\Model\Analysis;
use Dregs\Model\Identity;
use Dregs\Model\Scores;
use InvalidArgumentException;

/**
 * The `$client->identities` namespace: everything you can read about one user.
 *
 * These methods are thin. They name the endpoint and hand the response to a model; the
 * transport, the retries, and the error mapping all live on the {@see Client}.
 */
final class Identities
{
    /**
     * @param Client $client The client whose credentials and base URL these calls use.
     *
     * @internal Built by {@see Client}. Reach it as `$client->identities`.
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Returns the identity, with its current scores, badges, and attributes.
     *
     * ```php
     * $identity = $client->identities->get('user_12345');
     *
     * echo $identity->displayEmail, ' scored ', $identity->humanityScore, PHP_EOL;
     * ```
     *
     * @param string $identityId Your own id for the user. Escaped for you, so an email
     *                           address or anything else with awkward characters in it is
     *                           fine to pass straight through.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function get(string $identityId): Identity
    {
        return Identity::fromApi(Payload::asMap($this->client->request('GET', self::path($identityId))));
    }

    /**
     * Returns the current category scores.
     *
     * This is the cheap read and the one most integrations want. It reports the scores Dregs
     * has already computed without triggering any work. For the observations behind them, use
     * {@see self::analysis()}.
     *
     * Scoring is asynchronous: scores appear moments after the events that move them, not in
     * the same breath. Read them at a decision point rather than immediately after a
     * `track()` call. A category that has not been scored yet is absent, so a brand-new
     * identity comes back empty.
     *
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function scores(string $identityId): Scores
    {
        $payload = $this->client->request('GET', self::path($identityId, '/scores'));

        return Scores::fromApi(Payload::asObjects($payload));
    }

    /**
     * Returns the most recent analysis cycle, with the observations behind each score.
     *
     * Use this when you need to show or log *why* an identity scored the way it did. It is
     * the heavier read of the two, so reach for {@see self::scores()} when a number is all
     * you are branching on.
     *
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        The identity is unknown, or it has not been analyzed
     *                                  yet. The second is the ordinary state of a new
     *                                  identity rather than a fault.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function analysis(string $identityId): Analysis
    {
        $payload = $this->client->request('GET', self::path($identityId, '/analysis'));

        return Analysis::fromApi(Payload::asMap($payload));
    }

    /**
     * Queues a re-analysis of the identity.
     *
     * This returns as soon as the job is queued, not when it has run, so do not expect fresh
     * scores on the next line. Dregs rescores on its own as events arrive, which means you
     * rarely need this outside a support or backfill flow.
     *
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function analyze(string $identityId): void
    {
        $this->client->request('POST', self::path($identityId, '/actions/analyze'));
    }

    /**
     * Builds the path for one identity, escaping the id.
     *
     * Identity ids are the caller's own user ids and routinely contain characters that need
     * escaping, an email address being the common one.
     *
     * @throws InvalidArgumentException
     */
    private static function path(string $identityId, string $suffix = ''): string
    {
        if ($identityId === '') {
            throw new InvalidArgumentException('An identity id is required.');
        }

        return '/identities/' . rawurlencode($identityId) . $suffix;
    }
}
