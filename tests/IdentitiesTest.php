<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use Dregs\Model\Category;
use Dregs\Tests\Support\FakeTransport;
use Dregs\Tests\Support\Items;
use Dregs\Tests\Support\Responses;
use InvalidArgumentException;

/**
 * The identities namespace and the models it returns.
 */
final class IdentitiesTest extends TestCase
{
    private const IDENTITY = [
        'id' => 'user_12345',
        'displayName' => 'Ada Lovelace',
        'displayEmail' => 'ada@example.com',
        'displayUsername' => 'ada',
        'humanityScore' => 85,
        'authenticityScore' => 72,
        'uniquenessScore' => 91,
        'behaviorScore' => 68,
        'createdAt' => '2026-09-01T10:00:00Z',
        'lastTrackedAt' => '2026-09-21T14:20:00Z',
        'disregarded' => false,
        'badges' => [
            [
                'slug' => 'behavior.account-takeover-signal',
                'name' => 'Account Takeover Suspected',
                'type' => 'WARNING',
                'explanation' => 'A password reset from a device never seen before.',
                'metadata' => ['devices' => 2],
            ],
        ],
        'data' => ['email' => 'ada@example.com', 'plan' => 'pro'],
    ];

    private const ANALYSIS = [
        'id' => 2000871,
        'identityId' => 'user_12345',
        'scores' => [
            [
                'category' => 'HUMANITY',
                'value' => 85,
                'observations' => [
                    [
                        'category' => 'HUMANITY',
                        'id' => 'humanity.user-agent',
                        'label' => 'User Agent Analysis',
                        'explanation' => 'Browser fingerprint consistent with Chrome on macOS',
                        'value' => 0.92,
                        'confidence' => 0.85,
                        'weight' => 0.85,
                        'metadata' => ['browser' => 'Chrome'],
                    ],
                ],
            ],
            ['category' => 'BEHAVIOR', 'value' => 68, 'observations' => []],
        ],
        'eventCount' => 47,
        'deviceCount' => 2,
        'durationMillis' => 312,
        'startedAt' => '2026-09-21T14:22:09Z',
        'finishedAt' => '2026-09-21T14:22:09Z',
    ];

    public function testGetParsesAnIdentity(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(self::IDENTITY))->identities->get('user_12345');

        self::assertSame('user_12345', $identity->id);
        self::assertSame('Ada Lovelace', $identity->displayName);
        self::assertSame('ada@example.com', $identity->displayEmail);
        self::assertSame('ada', $identity->displayUsername);
        self::assertSame(85, $identity->humanityScore);
        self::assertSame(68, $identity->behaviorScore);
        self::assertFalse($identity->disregarded);
        self::assertSame('pro', $identity->data['plan'] ?? null);
    }

    public function testGetParsesTimestamps(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(self::IDENTITY))->identities->get('user_12345');

        self::assertNotNull($identity->createdAt);
        self::assertSame('2026-09-01T10:00:00+00:00', $identity->createdAt->format('c'));
        self::assertNotNull($identity->lastTrackedAt);
        self::assertNull($identity->lastScoredAt);
    }

    public function testGetParsesBadges(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(self::IDENTITY))->identities->get('user_12345');

        self::assertCount(1, $identity->badges);

        $badge = Items::first($identity->badges);

        self::assertSame('Account Takeover Suspected', $badge->name);
        self::assertSame('behavior.account-takeover-signal', $badge->slug);
        self::assertSame('WARNING', $badge->type);
        self::assertSame(2, $badge->metadata['devices'] ?? null);
    }

    public function testAnIdentityExposesTheSameScoresViewAsTheScoresCall(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(self::IDENTITY))->identities->get('user_12345');

        self::assertSame(85, $identity->scores()->humanity());
        self::assertSame(72, $identity->scores()->authenticity());
        self::assertSame(91, $identity->scores()->uniqueness());
        self::assertSame(68, $identity->scores()->behavior());
        self::assertCount(4, $identity->scores());
    }

    public function testAnUnscoredIdentityHasAnEmptyScoresView(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(['id' => 'user_12345']))
            ->identities->get('user_12345');

        self::assertTrue($identity->scores()->isEmpty());
        self::assertNull($identity->scores()->humanity());
    }

    public function testGetRequestsTheRightPath(): void
    {
        $transport = FakeTransport::alwaysJson(self::IDENTITY);

        $this->client($transport)->identities->get('user_12345');

        $request = $transport->lastRequest();

        self::assertSame('GET', $request->method);
        self::assertSame('/identities/user_12345', $request->pathAfter(self::BASE_URL));
        self::assertNull($request->body);
    }

    public function testAnIdentityIdIsEscaped(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'ada@example.com']);

        $this->client($transport)->identities->get('ada@example.com');

        self::assertSame(
            '/identities/ada%40example.com',
            $transport->lastRequest()->pathAfter(self::BASE_URL)
        );
    }

    public function testAnIdentityIdWithSlashesIsEscapedToo(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'tenant/7']);

        $this->client($transport)->identities->get('tenant/7');

        self::assertSame(
            '/identities/tenant%2F7',
            $transport->lastRequest()->pathAfter(self::BASE_URL)
        );
    }

    public function testAnEmptyIdentityIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/identity id is required/');

        $this->client(new FakeTransport())->identities->get('');
    }

    public function testScoresExposesEachCategoryByName(): void
    {
        $scores = $this->client($this->scoresTransport())->identities->scores('user_12345');

        self::assertSame(85, $scores->humanity());
        self::assertSame(72, $scores->authenticity());
        self::assertSame(91, $scores->uniqueness());
        self::assertSame(68, $scores->behavior());
    }

    public function testScoresIsCountableAndIterable(): void
    {
        $scores = $this->client($this->scoresTransport())->identities->scores('user_12345');

        self::assertCount(4, $scores);
        self::assertSame(4, $scores->count());
        self::assertFalse($scores->isEmpty());

        $categories = [];

        foreach ($scores as $score) {
            $categories[] = $score->category;
        }

        self::assertSame(
            [Category::Humanity, Category::Authenticity, Category::Uniqueness, Category::Behavior],
            $categories
        );
    }

    public function testScoresCanBeFetchedWholeByCategory(): void
    {
        $scores = $this->client($this->scoresTransport())->identities->scores('user_12345');

        self::assertSame(85, $scores->get(Category::Humanity)?->value);
        self::assertSame(Items::first($scores), $scores->get(Category::Humanity));
    }

    public function testAnUnscoredCategoryReadsAsNull(): void
    {
        $transport = FakeTransport::always(
            Responses::jsonList(200, [['category' => 'HUMANITY', 'value' => 85]])
        );

        $scores = $this->client($transport)->identities->scores('user_12345');

        self::assertSame(85, $scores->humanity());
        self::assertNull($scores->behavior());
        self::assertNull($scores->get(Category::Behavior));
    }

    public function testAnIdentityWithNoScoresYetIsEmpty(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, []));

        $scores = $this->client($transport)->identities->scores('user_12345');

        self::assertCount(0, $scores);
        self::assertTrue($scores->isEmpty());
        self::assertNull($scores->humanity());
    }

    public function testScoresCarryNoObservations(): void
    {
        $scores = $this->client($this->scoresTransport())->identities->scores('user_12345');

        self::assertSame([], Items::first($scores)->observations);
    }

    public function testScoresRequestsTheRightPath(): void
    {
        $transport = $this->scoresTransport();

        $this->client($transport)->identities->scores('user_12345');

        self::assertSame(
            '/identities/user_12345/scores',
            $transport->lastRequest()->pathAfter(self::BASE_URL)
        );
    }

    public function testAnalysisParsesACycleAndItsObservations(): void
    {
        $analysis = $this->client(FakeTransport::alwaysJson(self::ANALYSIS))
            ->identities->analysis('user_12345');

        self::assertSame(2000871, $analysis->id);
        self::assertSame('user_12345', $analysis->identityId);
        self::assertSame(47, $analysis->eventCount);
        self::assertSame(2, $analysis->deviceCount);
        self::assertSame(312, $analysis->durationMillis);
        self::assertSame(85, $analysis->scores->humanity());
        self::assertNotNull($analysis->finishedAt);

        $observation = Items::first(Items::first($analysis->scores)->observations);

        self::assertSame('humanity.user-agent', $observation->id);
        self::assertSame('User Agent Analysis', $observation->label);
        self::assertSame(0.92, $observation->value);
        self::assertSame(0.85, $observation->confidence);
        self::assertSame(0.85, $observation->weight);
        self::assertSame('Chrome', $observation->metadata['browser'] ?? null);
    }

    public function testAnalysisFlattensObservationsAcrossCategories(): void
    {
        $analysis = $this->client(FakeTransport::alwaysJson(self::ANALYSIS))
            ->identities->analysis('user_12345');

        self::assertCount(1, $analysis->observations());
        self::assertSame(Category::Humanity, Items::first($analysis->observations())->category);
    }

    public function testAnalysisRequestsTheRightPath(): void
    {
        $transport = FakeTransport::alwaysJson(self::ANALYSIS);

        $this->client($transport)->identities->analysis('user_12345');

        self::assertSame(
            '/identities/user_12345/analysis',
            $transport->lastRequest()->pathAfter(self::BASE_URL)
        );
    }

    public function testAnalyzePostsToTheActionEndpoint(): void
    {
        $transport = FakeTransport::always(Responses::empty(201));

        $this->client($transport)->identities->analyze('user_12345');

        $request = $transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame(
            '/identities/user_12345/actions/analyze',
            $request->pathAfter(self::BASE_URL)
        );
        self::assertNull($request->body);
        self::assertNull($request->header('Content-Type'));
    }

    public function testAnUnknownFieldSurvivesOnRaw(): void
    {
        $transport = FakeTransport::alwaysJson(['id' => 'user_12345', 'somethingNew' => 42]);

        $identity = $this->client($transport)->identities->get('user_12345');

        self::assertSame(42, $identity->raw['somethingNew'] ?? null);
    }

    public function testAnUnknownCategoryDoesNotBreakParsing(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, [
            ['category' => 'REPUTATION', 'value' => 50],
            ['category' => 'HUMANITY', 'value' => 85],
        ]));

        $scores = $this->client($transport)->identities->scores('user_12345');

        self::assertCount(2, $scores);
        self::assertSame(85, $scores->humanity());
        self::assertNull(Items::first($scores)->category);
        self::assertSame('REPUTATION', Items::first($scores)->raw['category'] ?? null);
    }

    public function testACategoryIsMatchedCaseInsensitively(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, [['category' => 'humanity', 'value' => 85]]));

        self::assertSame(85, $this->client($transport)->identities->scores('user_12345')->humanity());
    }

    public function testAMissingFieldBecomesNullRatherThanAnError(): void
    {
        $identity = $this->client(FakeTransport::alwaysJson(['id' => 'user_12345']))
            ->identities->get('user_12345');

        self::assertNull($identity->displayName);
        self::assertNull($identity->humanityScore);
        self::assertNull($identity->createdAt);
        self::assertSame([], $identity->badges);
        self::assertSame([], $identity->data);
    }

    public function testAFieldOfAnUnexpectedTypeIsIgnored(): void
    {
        $transport = FakeTransport::alwaysJson([
            'id' => 'user_12345',
            'displayName' => ['unexpectedly', 'a', 'list'],
            'humanityScore' => 'eighty-five',
            'createdAt' => 'whenever',
            'badges' => 'not a list',
            'data' => 'not an object',
        ]);

        $identity = $this->client($transport)->identities->get('user_12345');

        self::assertNull($identity->displayName);
        self::assertNull($identity->humanityScore);
        self::assertNull($identity->createdAt);
        self::assertSame([], $identity->badges);
        self::assertSame([], $identity->data);
    }

    public function testAScoreSentAsAFloatIsStillReadAsAnInteger(): void
    {
        $transport = FakeTransport::always(Responses::jsonList(200, [['category' => 'HUMANITY', 'value' => 85.0]]));

        self::assertSame(85, $this->client($transport)->identities->scores('user_12345')->humanity());
    }

    public function testAnObservationSentAsAnIntegerIsStillReadAsAFloat(): void
    {
        $transport = FakeTransport::alwaysJson([
            'id' => 1,
            'identityId' => 'user_12345',
            'scores' => [
                [
                    'category' => 'HUMANITY',
                    'value' => 100,
                    'observations' => [['category' => 'HUMANITY', 'id' => 'a.b', 'value' => 1]],
                ],
            ],
        ]);

        $analysis = $this->client($transport)->identities->analysis('user_12345');

        self::assertSame(1.0, Items::first($analysis->observations())->value);
    }

    public function testANonObjectResponseDoesNotBlowUp(): void
    {
        $transport = FakeTransport::always(Responses::text(200, '"a bare string"'));

        self::assertNull($this->client($transport)->identities->get('user_12345')->id);
    }

    private function scoresTransport(): FakeTransport
    {
        return FakeTransport::always(Responses::jsonList(200, [
            ['category' => 'HUMANITY', 'value' => 85],
            ['category' => 'AUTHENTICITY', 'value' => 72],
            ['category' => 'UNIQUENESS', 'value' => 91],
            ['category' => 'BEHAVIOR', 'value' => 68],
        ]));
    }

    private function client(FakeTransport $transport): Client
    {
        return new Client(self::SECRET_KEY, self::BASE_URL, maxRetries: 0, httpClient: $transport);
    }
}
