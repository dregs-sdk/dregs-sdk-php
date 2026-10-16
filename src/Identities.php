<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Arguments;
use Dregs\Internal\Lister;
use Dregs\Internal\Payload;
use Dregs\Model\Analysis;
use Dregs\Model\Identity;
use Dregs\Model\IdentityReview;
use Dregs\Model\LinkedIdentity;
use Dregs\Model\Page;
use Dregs\Model\ScoreHistoryEntry;
use Dregs\Model\Scores;
use Generator;
use InvalidArgumentException;

/**
 * The `$client->identities` namespace: the users Dregs is scoring, and everything about them.
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
     * Returns one page of identities, most recently updated first.
     *
     * ```php
     * $page = $client->identities->list(term: 'example.com', pageSize: 50);
     *
     * foreach ($page as $identity) {
     *     echo $identity->id, PHP_EOL;
     * }
     * ```
     *
     * Use {@see self::listAll()} to walk every page.
     *
     * @param string|null $term      Free text matched against ids, names, emails, and
     *                               usernames.
     * @param string|null $group     Only members of the group with this id. Pair it with
     *                               `$groupType` when your group ids are not unique across
     *                               types.
     * @param string|null $groupType Only members of groups of this type.
     * @param string|null $device    Only identities seen on the device with this fingerprint.
     * @param string|null $badge     Only identities carrying the badge with this slug.
     * @param int         $page      The page to return, counting from 0.
     * @param int|null    $pageSize  Items per page, at most 100.
     * @param string|null $sort      The field to sort on, such as "lastTrackedAt" or
     *                               "humanityScore".
     * @param string|null $order     "asc" or "desc".
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException A negative page, or an order other than "asc" or
     *                                  "desc".
     *
     * @return Page<Identity>
     */
    public function list(
        ?string $term = null,
        ?string $group = null,
        ?string $groupType = null,
        ?string $device = null,
        ?string $badge = null,
        int $page = 0,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Page {
        return $this->lister($term, $group, $groupType, $device, $badge, $pageSize, $sort, $order)->page($page);
    }

    /**
     * Yields every identity that matches, fetching pages as it goes. See {@see self::list()}.
     *
     * ```php
     * foreach ($client->identities->listAll(group: 'org_678', sort: 'humanityScore', order: 'asc') as $identity) {
     *     // ...
     * }
     * ```
     *
     * The arguments are checked when you call this, but no request is made until you start
     * iterating.
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An order other than "asc" or "desc".
     *
     * @return Generator<int, Identity, mixed, void>
     */
    public function listAll(
        ?string $term = null,
        ?string $group = null,
        ?string $groupType = null,
        ?string $device = null,
        ?string $badge = null,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Generator {
        return $this->lister($term, $group, $groupType, $device, $badge, $pageSize, $sort, $order)->walk();
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
     * Returns an analysis cycle, with the observations behind each score.
     *
     * Use this when you need to show or log *why* an identity scored the way it did. It is
     * the heavier read of the two, so reach for {@see self::scores()} when a number is all
     * you are branching on.
     *
     * @param string   $identityId Your own id for the user.
     * @param int|null $cycleId    A past cycle to return, from {@see self::history()}. The
     *                             most recent cycle when omitted.
     *
     * @throws NotFoundException        The identity is unknown, or it has not been analyzed
     *                                  yet. The second is the ordinary state of a new
     *                                  identity rather than a fault.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function analysis(string $identityId, ?int $cycleId = null): Analysis
    {
        $suffix = $cycleId === null ? '/analysis' : '/analysis/' . Arguments::segment($cycleId, 'A cycle id');

        $payload = $this->client->request('GET', self::path($identityId, $suffix));

        return Analysis::fromApi(Payload::asMap($payload));
    }

    /**
     * Returns the scores of past analysis cycles, newest first.
     *
     * ```php
     * foreach ($client->identities->history('user_12345', limit: 10) as $entry) {
     *     echo $entry->finishedAt?->format('c'), ' ', $entry->humanity, PHP_EOL;
     * }
     * ```
     *
     * @param string   $identityId Your own id for the user.
     * @param int|null $limit      The most cycles to return. The API's default is 100.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     *
     * @return list<ScoreHistoryEntry>
     */
    public function history(string $identityId, ?int $limit = null): array
    {
        $query = $limit === null ? [] : ['limit' => $limit];

        $payload = $this->client->request('GET', self::path($identityId, '/history'), null, $query);

        return array_map(ScoreHistoryEntry::fromApi(...), Payload::asObjects($payload));
    }

    /**
     * Returns the other identities Dregs believes are connected to this one, such as by a
     * shared device or address.
     *
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     *
     * @return list<LinkedIdentity>
     */
    public function links(string $identityId): array
    {
        $payload = $this->client->request('GET', self::path($identityId, '/links'));

        return array_map(LinkedIdentity::fromApi(...), Payload::asObjects($payload));
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
     * Updates an identity and returns it.
     *
     * ```php
     * // Leave your own staff and test accounts out of scoring and escalations.
     * $client->identities->update('qa_bot_7', disregarded: true);
     * ```
     *
     * @param string                    $identityId  Your own id for the user.
     * @param bool|null                 $disregarded True to leave the identity out of scoring
     *                                               and escalations, such as for your own
     *                                               staff or test accounts. Linked identities
     *                                               are rescored.
     * @param array<string, mixed>|null $attributes  Attributes to merge into the identity, as
     *                                               `identityData` on
     *                                               {@see Client::track()} would.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty, or neither argument was given.
     */
    public function update(string $identityId, ?bool $disregarded = null, ?array $attributes = null): Identity
    {
        $body = [];

        if ($disregarded !== null) {
            $body['disregarded'] = $disregarded;
        }

        if ($attributes !== null) {
            $body['attributes'] = Arguments::attributes($attributes);
        }

        if ($body === []) {
            throw new InvalidArgumentException('Pass disregarded, attributes, or both.');
        }

        $payload = $this->client->request('PATCH', self::path($identityId), $body);

        return Identity::fromApi(Payload::asMap($payload));
    }

    /**
     * Returns the AI reviews requested for this identity, newest first.
     *
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     *
     * @return list<IdentityReview>
     */
    public function reviews(string $identityId): array
    {
        $payload = $this->client->request('GET', self::path($identityId, '/reviews'));

        return array_map(IdentityReview::fromApi(...), Payload::asObjects($payload));
    }

    /**
     * Asks Dregs's AI reviewer to look over the identity's analysis.
     *
     * The review runs in the background, so the result comes back with a status of
     * "PENDING". Read it again with {@see self::reviews()} once it has finished.
     *
     * ```php
     * $review = $client->identities->review('user_12345', feedback: 'This is a long-standing customer.');
     * ```
     *
     * @param string      $identityId Your own id for the user.
     * @param string|null $feedback   What you think the analysis got wrong, such as "this is
     *                                a real customer".
     *
     * @throws NotFoundException        Dregs has never seen this identity.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function review(string $identityId, ?string $feedback = null): IdentityReview
    {
        $body = $feedback !== null && $feedback !== '' ? ['feedback' => $feedback] : [];

        $payload = $this->client->request('POST', self::path($identityId, '/actions/review'), $body);

        return IdentityReview::fromApi(Payload::asMap($payload));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return Lister<Identity>
     */
    private function lister(
        ?string $term,
        ?string $group,
        ?string $groupType,
        ?string $device,
        ?string $badge,
        ?int $pageSize,
        ?string $sort,
        ?string $order,
    ): Lister {
        $filters = [
            'term' => $term,
            'group' => $group,
            'groupType' => $groupType,
            'device' => $device,
            'badge' => $badge,
        ];

        return new Lister($this->client, '/identities', Identity::fromApi(...), $filters, $pageSize, $sort, $order);
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
        return '/identities/' . Arguments::segment($identityId, 'An identity id') . $suffix;
    }
}
