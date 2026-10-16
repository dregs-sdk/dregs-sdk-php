<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Arguments;
use Dregs\Internal\Lister;
use Dregs\Internal\Payload;
use Dregs\Model\Escalation;
use Dregs\Model\EscalationSummary;
use Dregs\Model\Page;
use Generator;
use InvalidArgumentException;

/**
 * The `$client->escalations` namespace: the escalations your escalation rules open, and
 * moving them along.
 *
 * Statuses and severities may be given in any case; they are sent upper-cased, as the API
 * spells them.
 */
final class Escalations
{
    /**
     * The statuses an escalation moves through.
     *
     * @var list<string>
     */
    public const STATUSES = ['OPEN', 'ACKNOWLEDGED', 'CLOSED'];

    /**
     * @param Client $client The client whose credentials and base URL these calls use.
     *
     * @internal Built by {@see Client}. Reach it as `$client->escalations`.
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Returns one page of escalations, newest first.
     *
     * @param string|null     $status   Only escalations in this status: "open",
     *                                  "acknowledged", or "closed".
     * @param string|null     $severity Only escalations of this severity: "info", "warning",
     *                                  or "critical".
     * @param string|null     $identity Only escalations about this identity.
     * @param int|string|null $rule     Only escalations opened by the escalation rule with
     *                                  this id.
     * @param int             $page     The page to return, counting from 0.
     * @param int|null        $pageSize Items per page, at most 100.
     * @param string|null     $sort     The field to sort on, such as "openedAt" or
     *                                  "severity".
     * @param string|null     $order    "asc" or "desc".
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException A negative page, an unknown status, or an order other
     *                                  than "asc" or "desc".
     *
     * @return Page<Escalation>
     */
    public function list(
        ?string $status = null,
        ?string $severity = null,
        ?string $identity = null,
        int|string|null $rule = null,
        int $page = 0,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Page {
        return $this->lister($status, $severity, $identity, $rule, $pageSize, $sort, $order)->page($page);
    }

    /**
     * Yields every escalation that matches, fetching pages as it goes. See
     * {@see self::list()}.
     *
     * ```php
     * foreach ($client->escalations->listAll(status: 'open', severity: 'critical') as $escalation) {
     *     notifyOnCall($escalation->identity?->id);
     *     $client->escalations->update($escalation->id, status: 'acknowledged');
     * }
     * ```
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An unknown status, or an order other than "asc" or
     *                                  "desc".
     *
     * @return Generator<int, Escalation, mixed, void>
     */
    public function listAll(
        ?string $status = null,
        ?string $severity = null,
        ?string $identity = null,
        int|string|null $rule = null,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Generator {
        return $this->lister($status, $severity, $identity, $rule, $pageSize, $sort, $order)->walk();
    }

    /**
     * Returns the escalation with this id.
     *
     * @param int|string $escalationId The escalation's id.
     *
     * @throws NotFoundException        No escalation has this id.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty.
     */
    public function get(int|string $escalationId): Escalation
    {
        return Escalation::fromApi(Payload::asMap($this->client->request('GET', self::path($escalationId))));
    }

    /**
     * Moves the escalation to `$status`, and returns it.
     *
     * @param int|string $escalationId The escalation's id.
     * @param string     $status       "open", "acknowledged", or "closed".
     *
     * @throws NotFoundException        No escalation has this id.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty, or the status unknown.
     */
    public function update(int|string $escalationId, string $status): Escalation
    {
        $body = ['status' => self::status($status)];

        $payload = $this->client->request('PATCH', self::path($escalationId), $body);

        return Escalation::fromApi(Payload::asMap($payload));
    }

    /**
     * Returns how many escalations are open, acknowledged, and closed.
     *
     * @throws ApiException        Dregs answered with an error status.
     * @throws ConnectionException Dregs could not be reached.
     */
    public function summary(): EscalationSummary
    {
        return EscalationSummary::fromApi(Payload::asMap($this->client->request('GET', '/escalations/summary')));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return Lister<Escalation>
     */
    private function lister(
        ?string $status,
        ?string $severity,
        ?string $identity,
        int|string|null $rule,
        ?int $pageSize,
        ?string $sort,
        ?string $order,
    ): Lister {
        $filters = [
            'status' => $status === null ? null : self::status($status),
            'severity' => $severity === null || $severity === '' ? null : strtoupper($severity),
            'identity' => $identity,
            'rule' => $rule,
        ];

        return new Lister($this->client, '/escalations', Escalation::fromApi(...), $filters, $pageSize, $sort, $order);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function status(string $status): string
    {
        $upper = strtoupper($status);

        if (!in_array($upper, self::STATUSES, true)) {
            throw new InvalidArgumentException("status is 'open', 'acknowledged', or 'closed'.");
        }

        return $upper;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function path(int|string $escalationId): string
    {
        return '/escalations/' . Arguments::segment($escalationId, 'An escalation id');
    }
}
