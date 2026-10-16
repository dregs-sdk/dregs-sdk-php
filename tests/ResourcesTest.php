<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Model\Device;
use Dregs\Model\Event;
use Dregs\Model\Group;
use Dregs\Model\Identity;
use Dregs\Tests\Support\FakeTransport;
use Dregs\Tests\Support\Items;
use Dregs\Tests\Support\Responses;
use InvalidArgumentException;

/**
 * The groups, events, devices, and escalations namespaces, the rest of identities, and paging.
 */
final class ResourcesTest extends TestCase
{
    private const GROUP = [
        'type' => 'organization',
        'id' => 'org_678',
        'displayName' => 'Acme Inc',
        'identityCount' => 12,
        'createdAt' => '2026-10-01T10:00:00Z',
        'lastTrackedAt' => '2026-10-15T10:00:00Z',
        'data' => ['name' => 'Acme Inc', 'plan' => 'enterprise'],
    ];

    // Paging ---------------------------------------------------------------------------------

    public function testListSendsThePageAndFiltersAndDropsUnsetOnes(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([]));

        $this->client($transport)->identities->list(
            term: 'ada',
            page: 2,
            pageSize: 50,
            sort: 'humanityScore',
            order: 'asc',
        );

        $request = $transport->lastRequest();
        $query = $request->query();

        self::assertSame('GET', $request->method);
        self::assertSame('/identities', $request->pathAfter(self::BASE_URL));
        self::assertSame('ada', $query['term'] ?? null);
        self::assertSame('2', $query['pageNumber'] ?? null);
        self::assertSame('50', $query['pageSize'] ?? null);
        self::assertSame('humanityScore', $query['sort'] ?? null);
        self::assertSame('ASC', $query['order'] ?? null);
        self::assertArrayNotHasKey('group', $query);
        self::assertArrayNotHasKey('badge', $query);
        self::assertNull($request->body);
    }

    public function testAListWithNoArgumentsSendsOnlyThePageNumber(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([]));

        $this->client($transport)->devices->list();

        self::assertSame(['pageNumber' => '0'], $transport->lastRequest()->query());
    }

    public function testFilterValuesAreEscaped(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([]));

        $this->client($transport)->events->list(identity: 'ada@example.com', term: 'a b&c');

        $request = $transport->lastRequest();

        self::assertStringContainsString('identity=ada%40example.com', $request->rawQuery());
        self::assertStringContainsString('term=a%20b%26c', $request->rawQuery());
        self::assertSame('a b&c', $request->query()['term'] ?? null);
    }

    public function testAPageIsCountableAndIterableWithItsPosition(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([['id' => 'a'], ['id' => 'b']], last: false, total: 5));

        $page = $this->client($transport)->identities->list();

        $ids = [];

        foreach ($page as $identity) {
            $ids[] = $identity->id;
        }

        self::assertSame(['a', 'b'], $ids);
        self::assertCount(2, $page);
        self::assertSame(5, $page->totalElements);
        self::assertSame(0, $page->pageNumber);
        self::assertSame(2, $page->pageSize);
        self::assertTrue($page->first);
        self::assertFalse($page->last);
        self::assertTrue($page->hasNext());
        self::assertFalse($page->isEmpty());
        self::assertSame(5, $page->raw['totalElements'] ?? null);
        self::assertInstanceOf(Identity::class, Items::first($page->all()));
    }

    public function testTheLastPageHasNoNext(): void
    {
        $page = $this->client(FakeTransport::alwaysJson(self::page([['id' => 'a']])))->identities->list();

        self::assertFalse($page->hasNext());
    }

    public function testAPageMissingItsFieldsReadsAsOneEmptyLastPage(): void
    {
        $page = $this->client(FakeTransport::alwaysJson(['content' => 'not a list']))->identities->list();

        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->totalElements);
        self::assertTrue($page->last);
        self::assertFalse($page->hasNext());
    }

    public function testListAllWalksEveryPage(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::json(200, self::page([['type' => 'team', 'id' => '1'], ['type' => 'team', 'id' => '2']], last: false)),
            Responses::json(200, self::page([['type' => 'team', 'id' => '3']], number: 1)),
        );

        $ids = array_map(
            static fn (Group $group): ?string => $group->id,
            iterator_to_array($this->client($transport)->groups->listAll(groupType: 'team'), false),
        );

        self::assertSame(['1', '2', '3'], $ids);
        self::assertSame(2, $transport->callCount());
        self::assertSame('0', $transport->request(0)->query()['pageNumber'] ?? null);
        self::assertSame('1', $transport->request(1)->query()['pageNumber'] ?? null);
        self::assertSame('team', $transport->request(0)->query()['type'] ?? null);
        self::assertSame('team', $transport->request(1)->query()['type'] ?? null);
    }

    public function testListAllKeysItsItemsInOneRunningSequence(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::json(200, self::page([['fingerprint' => 'a']], last: false)),
            Responses::json(200, self::page([['fingerprint' => 'b']], number: 1)),
        );

        $devices = iterator_to_array($this->client($transport)->devices->listAll());

        self::assertSame([0, 1], array_keys($devices));
        self::assertSame(['a', 'b'], array_map(static fn (Device $device): ?string => $device->fingerprint, $devices));
    }

    public function testListAllStopsOnAnEmptyPageEvenIfNotMarkedLast(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([], last: false));

        self::assertSame([], iterator_to_array($this->client($transport)->events->listAll(), false));
        self::assertSame(1, $transport->callCount());
    }

    public function testListAllMakesNoRequestUntilIterated(): void
    {
        $transport = new FakeTransport();

        $events = $this->client($transport)->events->listAll();

        self::assertSame(0, $transport->callCount());

        unset($events);
    }

    public function testANegativePageIsRefused(): void
    {
        $transport = new FakeTransport();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/from 0/');

        $this->client($transport)->devices->list(page: -1);
    }

    public function testAnUnknownOrderIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/asc/');

        $this->client(new FakeTransport())->escalations->list(order: 'sideways');
    }

    public function testAnUnknownOrderIsRefusedByListAllBeforeIterating(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/asc/');

        $this->client(new FakeTransport())->groups->listAll(order: 'sideways');
    }

    // Identities -----------------------------------------------------------------------------

    public function testAnalysisOfAPastCycle(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 2000871, 'identityId' => 'user_12345', 'scores' => []]);

        $analysis = $this->client($transport)->identities->analysis('user_12345', 2000871);

        self::assertSame(
            '/identities/user_12345/analysis/2000871',
            $transport->lastRequest()->pathAfter(self::BASE_URL)
        );
        self::assertSame(2000871, $analysis->id);
    }

    public function testHistory(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, [
            [
                'id' => 9,
                'humanityScore' => 80,
                'behaviorScore' => 60,
                'eventCount' => 3,
                'finishedAt' => '2026-10-01T00:00:00Z',
            ],
        ]));

        $history = $this->client($transport)->identities->history('user_12345', limit: 10);

        $request = $transport->lastRequest();
        $entry = Items::first($history);

        self::assertSame('/identities/user_12345/history', $request->pathAfter(self::BASE_URL));
        self::assertSame(['limit' => '10'], $request->query());
        self::assertSame(9, $entry->cycleId);
        self::assertSame(80, $entry->humanity);
        self::assertNull($entry->authenticity);
        self::assertSame(60, $entry->behavior);
        self::assertSame(3, $entry->eventCount);
        self::assertNotNull($entry->finishedAt);
    }

    public function testHistoryWithoutALimitSendsNoQuery(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, []));

        self::assertSame([], $this->client($transport)->identities->history('user_12345'));
        self::assertSame('', $transport->lastRequest()->rawQuery());
    }

    public function testLinks(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, [
            [
                'id' => 1,
                'targetIdentityId' => 'user_999',
                'targetDisplayName' => 'Bob',
                'type' => 'SAME_DEVICE',
                'value' => 0.9,
                'confidence' => 0.8,
            ],
        ]));

        $link = Items::first($this->client($transport)->identities->links('user_12345'));

        self::assertSame('/identities/user_12345/links', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertSame('user_999', $link->identityId);
        self::assertSame('Bob', $link->displayName);
        self::assertSame('SAME_DEVICE', $link->type);
        self::assertSame(0.9, $link->value);
        self::assertSame(0.8, $link->confidence);
    }

    public function testUpdateSendsOnlyWhatWasGiven(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'user_12345', 'disregarded' => true]);

        $identity = $this->client($transport)->identities->update('user_12345', disregarded: true);

        $request = $transport->lastRequest();

        self::assertSame('PATCH', $request->method);
        self::assertSame('/identities/user_12345', $request->pathAfter(self::BASE_URL));
        self::assertSame('{"disregarded":true}', $request->body);
        self::assertTrue($identity->disregarded);
    }

    public function testUpdateMergesAttributes(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'user_12345']);

        $this->client($transport)->identities->update('user_12345', disregarded: false, attributes: ['plan' => 'pro']);

        self::assertSame('{"disregarded":false,"attributes":{"plan":"pro"}}', $transport->lastRequest()->body);
    }

    public function testUpdateWithEmptyAttributesSendsAnEmptyObject(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'user_12345']);

        $this->client($transport)->identities->update('user_12345', attributes: []);

        self::assertSame('{"attributes":{}}', $transport->lastRequest()->body);
    }

    public function testUpdateWithNothingIsRefused(): void
    {
        $transport = new FakeTransport();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/disregarded/');

        $this->client($transport)->identities->update('user_12345');
    }

    public function testReviewAndReviews(): void
    {
        $transport = (new FakeTransport())->queue(
            Responses::json(201, [
                'id' => 7,
                'identityId' => 'user_12345',
                'status' => 'PENDING',
                'userFeedback' => 'real customer',
            ]),
            Responses::jsonList(200, [['id' => 7, 'status' => 'FINISHED', 'summary' => 'Looks fine.']]),
        );

        $client = $this->client($transport);

        $review = $client->identities->review('user_12345', feedback: 'real customer');
        $reviews = $client->identities->reviews('user_12345');

        $post = $transport->request(0);

        self::assertSame('POST', $post->method);
        self::assertSame('/identities/user_12345/actions/review', $post->pathAfter(self::BASE_URL));
        self::assertSame(['feedback' => 'real customer'], $post->json());
        self::assertSame('/identities/user_12345/reviews', $transport->request(1)->pathAfter(self::BASE_URL));
        self::assertSame(7, $review->id);
        self::assertSame('PENDING', $review->status);
        self::assertTrue($review->isPending());
        self::assertSame('real customer', $review->feedback);
        self::assertSame('Looks fine.', Items::first($reviews)->summary);
        self::assertFalse(Items::first($reviews)->isPending());
    }

    public function testAReviewWithoutFeedbackSendsAnEmptyObject(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 7, 'status' => 'PENDING']);

        $this->client($transport)->identities->review('user_12345');

        self::assertSame('{}', $transport->lastRequest()->body);
    }

    // Groups ---------------------------------------------------------------------------------

    public function testGetAGroup(): void
    {
        $transport = FakeTransport::alwaysJson(self::GROUP);

        $group = $this->client($transport)->groups->get('organization', 'org_678');

        self::assertSame('/groups/organization/org_678', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertSame('organization', $group->type);
        self::assertSame('org_678', $group->id);
        self::assertSame('Acme Inc', $group->displayName);
        self::assertSame(12, $group->identityCount);
        self::assertSame('enterprise', $group->data['plan'] ?? null);
        self::assertNotNull($group->createdAt);
        self::assertNotNull($group->lastTrackedAt);
        self::assertNull($group->updatedAt);
    }

    public function testCreateDefaultsTheTypeAndSendsAttributes(): void
    {
        $transport = FakeTransport::alwaysJson(self::GROUP, 201);

        $group = $this->client($transport)->groups->create('org_678', attributes: ['name' => 'Acme Inc']);

        $request = $transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('/groups', $request->pathAfter(self::BASE_URL));
        self::assertSame(
            ['type' => 'organization', 'id' => 'org_678', 'attributes' => ['name' => 'Acme Inc']],
            $request->json()
        );
        self::assertSame('org_678', $group->id);
    }

    public function testCreateWithNoAttributesSendsAnEmptyObject(): void
    {
        $transport = FakeTransport::alwaysJson(self::GROUP, 201);

        $this->client($transport)->groups->create('team_42', groupType: 'team');

        self::assertSame('{"type":"team","id":"team_42","attributes":{}}', $transport->lastRequest()->body);
    }

    public function testCreateRefusesAttributesThatAreAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/keyed by name/');

        // @phpstan-ignore argument.type (malformed on purpose: the signature rules this out)
        $this->client(new FakeTransport())->groups->create('org_678', attributes: ['Acme Inc']);
    }

    public function testCreateRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/group id/');

        $this->client(new FakeTransport())->groups->create('');
    }

    public function testUpdateAGroup(): void
    {
        $transport = FakeTransport::alwaysJson(self::GROUP);

        $this->client($transport)->groups->update('team', 'team_42', ['name' => 'Payments']);

        $request = $transport->lastRequest();

        self::assertSame('PATCH', $request->method);
        self::assertSame('/groups/team/team_42', $request->pathAfter(self::BASE_URL));
        self::assertSame(['attributes' => ['name' => 'Payments']], $request->json());
    }

    public function testMembership(): void
    {
        $transport = FakeTransport::always(Responses::empty(204));

        $client = $this->client($transport);

        $client->groups->addMember('team', 'team_42', 'ada@example.com');
        $client->groups->removeMember('team', 'team_42', 'ada@example.com');

        self::assertSame('PUT', $transport->request(0)->method);
        self::assertSame('DELETE', $transport->request(1)->method);

        foreach ([0, 1] as $index) {
            self::assertSame(
                '/groups/team/team_42/identities/ada%40example.com',
                $transport->request($index)->pathAfter(self::BASE_URL)
            );
            self::assertNull($transport->request($index)->body);
        }
    }

    public function testGroupPathSegmentsAreEscaped(): void
    {
        $transport = FakeTransport::alwaysJson(self::GROUP);

        $this->client($transport)->groups->get('team', 'eng/platform');

        self::assertSame('/groups/team/eng%2Fplatform', $transport->lastRequest()->pathAfter(self::BASE_URL));
    }

    public function testAMissingGroupIdIsRefusedBeforeAnyRequest(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->groups->get('team', '');

            self::fail('An empty group id should have been refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('group id', $exception->getMessage());
        }

        self::assertSame(0, $transport->callCount());
    }

    public function testAMissingGroupTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/group type/');

        $this->client(new FakeTransport())->groups->addMember('', 'team_42', 'user_12345');
    }

    public function testAMissingMemberIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/identity id/');

        $this->client(new FakeTransport())->groups->removeMember('team', 'team_42', '');
    }

    // Events ---------------------------------------------------------------------------------

    public function testGetParsesAnEventWithItsGroupsAndIdentity(): void
    {
        $transport = FakeTransport::alwaysJson([
            'id' => 'signup-991',
            'timestamp' => '2026-10-15T10:00:00Z',
            'identity' => ['id' => 'user_12345', 'label' => 'Ada', 'path' => '/api/identities/user_12345'],
            'groups' => [
                ['type' => 'team', 'id' => 'team_42', 'label' => 'Payments', 'path' => '/api/groups/team/team_42'],
            ],
            'eventSource' => 'php-sdk',
            'eventName' => 'user.signup',
            'canonicalType' => 'SIGNUP',
            'eventData' => ['plan' => 'pro'],
        ]);

        $event = $this->client($transport)->events->get('signup-991');

        self::assertSame('/events/signup-991', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertSame('signup-991', $event->id);
        self::assertNotNull($event->timestamp);
        self::assertSame('user.signup', $event->name);
        self::assertSame('php-sdk', $event->source);
        self::assertSame('SIGNUP', $event->canonicalType);
        self::assertSame('user_12345', $event->identity?->id);
        self::assertSame('Ada', $event->identity->label);
        self::assertSame('team', Items::first($event->groups)->type);
        self::assertSame('team_42', Items::first($event->groups)->id);
        self::assertSame('pro', $event->data['plan'] ?? null);
    }

    public function testAnEventWithoutAnIdentityOrGroups(): void
    {
        $event = $this->client(FakeTransport::alwaysJson(['id' => 'e1', 'identity' => null]))->events->get('e1');

        self::assertNull($event->identity);
        self::assertSame([], $event->groups);
        self::assertSame([], $event->data);
    }

    public function testListEventsFilters(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([['id' => 'e1', 'eventName' => 'user.login']]));

        $page = $this->client($transport)->events->list(identity: 'user_12345', group: 'team_42', groupType: 'team');

        $query = $transport->lastRequest()->query();

        self::assertSame('/events', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertSame('user_12345', $query['identity'] ?? null);
        self::assertSame('team_42', $query['group'] ?? null);
        self::assertSame('team', $query['groupType'] ?? null);
        self::assertSame(
            ['user.login'],
            array_map(static fn (Event $event): ?string => $event->name, $page->all())
        );
    }

    // Devices --------------------------------------------------------------------------------

    public function testUpdateADevice(): void
    {
        $transport = FakeTransport::alwaysJson([
            'fingerprint' => 'abc123',
            'disregarded' => true,
            'countryCode' => 'US',
            'latitude' => 37,
            'asn' => 'AS15169',
        ]);

        $device = $this->client($transport)->devices->update('abc123', disregarded: true);

        $request = $transport->lastRequest();

        self::assertSame('PATCH', $request->method);
        self::assertSame('/devices/abc123', $request->pathAfter(self::BASE_URL));
        self::assertSame(['disregarded' => true], $request->json());
        self::assertTrue($device->disregarded);
        self::assertSame('US', $device->countryCode);
        self::assertSame(37.0, $device->latitude);
        self::assertSame('AS15169', $device->asn);
    }

    public function testGetADevice(): void
    {
        $transport = FakeTransport::alwaysJson(['fingerprint' => 'abc123', 'lastSeenAt' => '2026-10-15T10:00:00Z']);

        $device = $this->client($transport)->devices->get('abc123');

        self::assertSame('/devices/abc123', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertFalse($device->disregarded);
        self::assertNotNull($device->lastSeenAt);
    }

    public function testAnEmptyFingerprintIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fingerprint/');

        $this->client(new FakeTransport())->devices->get('');
    }

    // Escalations ----------------------------------------------------------------------------

    public function testListUpperCasesStatusAndSeverity(): void
    {
        $transport = FakeTransport::alwaysJson(self::page([]));

        $this->client($transport)->escalations->list(status: 'open', severity: 'critical', rule: 3);

        $query = $transport->lastRequest()->query();

        self::assertSame('OPEN', $query['status'] ?? null);
        self::assertSame('CRITICAL', $query['severity'] ?? null);
        self::assertSame('3', $query['rule'] ?? null);
    }

    public function testListRefusesAnUnknownStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/acknowledged/');

        $this->client(new FakeTransport())->escalations->listAll(status: 'snoozed');
    }

    public function testUpdateAnEscalation(): void
    {
        $transport = FakeTransport::alwaysJson([
            'id' => 41,
            'status' => 'ACKNOWLEDGED',
            'severity' => 'WARNING',
            'identity' => ['id' => 'user_12345'],
            'escalationRule' => ['id' => 3, 'label' => 'Low humanity'],
            'scoreSnapshot' => ['humanity' => 12],
        ]);

        $escalation = $this->client($transport)->escalations->update(41, status: 'acknowledged');

        $request = $transport->lastRequest();

        self::assertSame('PATCH', $request->method);
        self::assertSame('/escalations/41', $request->pathAfter(self::BASE_URL));
        self::assertSame(['status' => 'ACKNOWLEDGED'], $request->json());
        self::assertSame(41, $escalation->id);
        self::assertSame('ACKNOWLEDGED', $escalation->status);
        self::assertFalse($escalation->isOpen());
        self::assertSame('user_12345', $escalation->identity?->id);
        self::assertSame('3', $escalation->rule?->id);
        self::assertSame('Low humanity', $escalation->rule->label);
        self::assertSame(12, $escalation->scores['humanity'] ?? null);
    }

    public function testUpdateRefusesAnUnknownStatus(): void
    {
        $transport = new FakeTransport();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/acknowledged/');

        $this->client($transport)->escalations->update(41, status: 'snoozed');
    }

    public function testGetAnEscalation(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 41, 'status' => 'OPEN']);

        $escalation = $this->client($transport)->escalations->get('41');

        self::assertSame('/escalations/41', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertTrue($escalation->isOpen());
    }

    public function testSummary(): void
    {
        $transport = FakeTransport::alwaysJson(['open' => 3, 'acknowledged' => 1, 'closed' => 20]);

        $summary = $this->client($transport)->escalations->summary();

        self::assertSame('/escalations/summary', $transport->lastRequest()->pathAfter(self::BASE_URL));
        self::assertSame([3, 1, 20], [$summary->open, $summary->acknowledged, $summary->closed]);
    }

    /**
     * A `V1Page` body, as the list endpoints send it.
     *
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function page(array $content, int $number = 0, bool $last = true, ?int $total = null): array
    {
        return [
            'pageNumber' => $number,
            'pageSize' => 2,
            'totalPages' => $last ? $number + 1 : $number + 2,
            'totalElements' => $total ?? count($content),
            'content' => $content,
            'first' => $number === 0,
            'last' => $last,
            'numberOfElements' => count($content),
        ];
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(self::SECRET_KEY, self::BASE_URL, maxRetries: 0, httpClient: $transport);
    }
}
