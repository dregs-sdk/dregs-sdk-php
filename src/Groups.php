<?php

declare(strict_types=1);

namespace Dregs;

use Dregs\Exception\ApiException;
use Dregs\Exception\ConnectionException;
use Dregs\Exception\NotFoundException;
use Dregs\Internal\Arguments;
use Dregs\Internal\Lister;
use Dregs\Internal\Payload;
use Dregs\Model\Group;
use Dregs\Model\Page;
use Generator;
use InvalidArgumentException;

/**
 * The `$client->groups` namespace: the organizations, teams, or workspaces your users belong
 * to.
 *
 * {@see Client::track()} already records groups and memberships as events arrive. These calls
 * are for reading them back, and for keeping Dregs in step when something changes outside an
 * event, such as a user leaving a team.
 *
 * A group is identified by its type and id together, so the calls that address one group take
 * both, type first.
 */
final class Groups
{
    /** The type of a group that does not name one. */
    public const DEFAULT_TYPE = 'organization';

    /**
     * @param Client $client The client whose credentials and base URL these calls use.
     *
     * @internal Built by {@see Client}. Reach it as `$client->groups`.
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Returns one page of groups, most recently active first.
     *
     * The groups in a list leave out their attributes; {@see self::get()} returns them.
     *
     * @param string|null $groupType Only groups of this type, such as "team".
     * @param string|null $identity  Only groups this identity belongs to.
     * @param string|null $term      Free text matched against group ids and names.
     * @param int         $page      The page to return, counting from 0.
     * @param int|null    $pageSize  Items per page, at most 100.
     * @param string|null $sort      The field to sort on, such as "displayName" or
     *                               "lastTrackedAt".
     * @param string|null $order     "asc" or "desc".
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException A negative page, or an order other than "asc" or
     *                                  "desc".
     *
     * @return Page<Group>
     */
    public function list(
        ?string $groupType = null,
        ?string $identity = null,
        ?string $term = null,
        int $page = 0,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Page {
        return $this->lister($groupType, $identity, $term, $pageSize, $sort, $order)->page($page);
    }

    /**
     * Yields every group that matches, fetching pages as it goes. See {@see self::list()}.
     *
     * ```php
     * foreach ($client->groups->listAll(groupType: 'team', identity: 'user_12345') as $team) {
     *     echo $team->id, ' ', $team->displayName, PHP_EOL;
     * }
     * ```
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An order other than "asc" or "desc".
     *
     * @return Generator<int, Group, mixed, void>
     */
    public function listAll(
        ?string $groupType = null,
        ?string $identity = null,
        ?string $term = null,
        ?int $pageSize = null,
        ?string $sort = null,
        ?string $order = null,
    ): Generator {
        return $this->lister($groupType, $identity, $term, $pageSize, $sort, $order)->walk();
    }

    /**
     * Returns the group, with its attributes.
     *
     * @param string $groupType The kind of group, such as "organization" or "team".
     * @param string $groupId   Your own id for the group.
     *
     * @throws NotFoundException        No group of this type has this id.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The type or the id was empty.
     */
    public function get(string $groupType, string $groupId): Group
    {
        return Group::fromApi(Payload::asMap($this->client->request('GET', self::path($groupType, $groupId))));
    }

    /**
     * Creates a group, or merges `$attributes` into the group that already has this type and
     * id, and returns it.
     *
     * Because it merges, this is safe to call on every sync.
     *
     * ```php
     * $client->groups->create('org_678', attributes: ['name' => 'Acme Inc', 'plan' => 'enterprise']);
     * ```
     *
     * @param string                    $groupId    Your own id for the group.
     * @param string|null               $groupType  The kind of group. "organization" when
     *                                              omitted.
     * @param array<string, mixed>|null $attributes Attributes such as a name or plan.
     *
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The id was empty, or the attributes were a list.
     */
    public function create(string $groupId, ?string $groupType = null, ?array $attributes = null): Group
    {
        $body = [
            'type' => $groupType === null || $groupType === '' ? self::DEFAULT_TYPE : $groupType,
            'id' => Arguments::required($groupId, 'A group id'),
            'attributes' => Arguments::attributes($attributes ?? []),
        ];

        return Group::fromApi(Payload::asMap($this->client->request('POST', '/groups', $body)));
    }

    /**
     * Merges `$attributes` into the group and returns it.
     *
     * @param string               $groupType  The kind of group.
     * @param string               $groupId    Your own id for the group.
     * @param array<string, mixed> $attributes Attributes to merge, such as a new plan.
     *
     * @throws NotFoundException        No group of this type has this id.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException The type or the id was empty, or the attributes were a
     *                                  list.
     */
    public function update(string $groupType, string $groupId, array $attributes): Group
    {
        $body = ['attributes' => Arguments::attributes($attributes)];

        $payload = $this->client->request('PATCH', self::path($groupType, $groupId), $body);

        return Group::fromApi(Payload::asMap($payload));
    }

    /**
     * Makes the identity a member of the group. Adding an existing member does nothing.
     *
     * @param string $groupType  The kind of group.
     * @param string $groupId    Your own id for the group.
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        The group or the identity is unknown.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An id or the type was empty.
     */
    public function addMember(string $groupType, string $groupId, string $identityId): void
    {
        $this->client->request('PUT', self::memberPath($groupType, $groupId, $identityId));
    }

    /**
     * Removes the identity from the group, such as when a user leaves a team.
     *
     * @param string $groupType  The kind of group.
     * @param string $groupId    Your own id for the group.
     * @param string $identityId Your own id for the user.
     *
     * @throws NotFoundException        The group or the identity is unknown.
     * @throws ApiException             Dregs answered with an error status.
     * @throws ConnectionException      Dregs could not be reached.
     * @throws InvalidArgumentException An id or the type was empty.
     */
    public function removeMember(string $groupType, string $groupId, string $identityId): void
    {
        $this->client->request('DELETE', self::memberPath($groupType, $groupId, $identityId));
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return Lister<Group>
     */
    private function lister(
        ?string $groupType,
        ?string $identity,
        ?string $term,
        ?int $pageSize,
        ?string $sort,
        ?string $order,
    ): Lister {
        $filters = ['type' => $groupType, 'identity' => $identity, 'term' => $term];

        return new Lister($this->client, '/groups', Group::fromApi(...), $filters, $pageSize, $sort, $order);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function path(string $groupType, string $groupId): string
    {
        return '/groups/' . Arguments::segment($groupType, 'A group type')
            . '/' . Arguments::segment($groupId, 'A group id');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function memberPath(string $groupType, string $groupId, string $identityId): string
    {
        return self::path($groupType, $groupId) . '/identities/' . Arguments::segment($identityId, 'An identity id');
    }
}
