# Dregs PHP SDK

[![Packagist](https://img.shields.io/packagist/v/dregs/dregs-sdk.svg)](https://packagist.org/packages/dregs/dregs-sdk)
[![PHP](https://img.shields.io/packagist/dependency-v/dregs/dregs-sdk/php.svg)](https://packagist.org/packages/dregs/dregs-sdk)
[![License](https://img.shields.io/packagist/l/dregs/dregs-sdk.svg)](LICENSE)

The official PHP client for [Dregs](https://dregs.com), which scores the users of your application
for fraud and abuse across four categories: humanity, authenticity, uniqueness, and behavior.

Send events from your backend, read back the scores and the observations behind them.

```bash
composer require dregs/dregs-sdk
```

The package has no required Composer dependencies. It talks to Dregs over ext-curl out of the box, so
`new Dregs\Client()` works in a project with nothing else installed — and it will use your
application's own PSR-18 client instead if you would rather it did. See
[Configuration](#configuration).

## Getting started

You need the **secret key** from an API credential, which you will find under **Settings → Credentials**
in the Dregs dashboard. It starts with `sk_`. The `pk_` public key is for the browser tracker and cannot
read identities or scores.

```php
use Dregs\Client;

$client = new Client($_ENV['DREGS_SECRET_KEY']);
```

The key is read from `DREGS_SECRET_KEY` when you do not pass one, so `new Client()` on its own is
usually enough. The client is stateless beyond its configuration: build one at startup, register it in
your container, and reuse it.

## Tracking events

```php
$client->track(
    'user.signup',
    identity: 'user_12345',
    data: ['plan' => 'pro', 'referrer' => 'partner-x'],
    identityData: ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'],
);
```

`identity` is your own id for the user — the same one you pass to `dregs.identify()` in the browser
tracker, and the one you look scores up by. It is required: a server-side event carries no device
signature, so the identity is the only thing tying the event to a user.

`identityData` carries attributes of the *user* rather than the event. The analyzers lean on these
heavily, so send them whenever you have them. Name the keys the way your application already does and
map them to Dregs's canonical fields under **Settings → Mappings**; the same goes for event names.

### Idempotency

Every event is sent with an `id`, which makes ingestion idempotent: reposting the same id returns the
original event instead of recording a second one. Pass the id your application already has, and a retry
after a timeout can never double-count.

```php
$client->track('purchase', identity: 'user_12345', eventId: "order-{$order->id}");
```

When you omit it the SDK generates one, which is what makes its own retries safe.

### What comes back

```php
$result = $client->track('user.signup', identity: 'user_12345');

$result->isAccepted();  // true when Dregs recorded the event
$result->id;            // the event's id
```

`isAccepted()` is `false` in the uncommon case where Dregs accepts the request without recording an
event. Failures that are yours to act on throw instead — see [Errors](#errors).

## Reading scores

```php
$scores = $client->identities->scores('user_12345');

$scores->humanity();      // 85
$scores->authenticity();  // 72
$scores->uniqueness();    // 91
$scores->behavior();      // 68
```

This is the cheap read and the one most integrations want. A category Dregs has not scored yet reads as
`null`, and a brand-new identity comes back empty. `Scores` is countable and iterable, so you can walk
it as well as ask it for a category by name.

Scoring is **asynchronous**. Scores appear moments after the events that move them, not in the same
breath, so read them at a decision point rather than immediately after a `track()` call.

```php
if ($scores->authenticity() !== null && $scores->authenticity() < 40) {
    holdForReview('user_12345');
}
```

### Seeing exactly why

The scores are the summary; the observations are the evidence. When you need to show or log *why* an
identity scored the way it did, ask for the analysis.

```php
$analysis = $client->identities->analysis('user_12345');

foreach ($analysis->observations() as $observation) {
    echo "{$observation->label}: {$observation->explanation} (value {$observation->value})", PHP_EOL;
}
```

Each observation carries the analyzer that produced it, a `value` from 0.0 (suspicious) to 1.0
(legitimate), a `confidence`, a `weight`, and the counts behind the finding in `metadata`. `analysis()`
throws `NotFoundException` until the identity has been analyzed at least once.

### The whole identity

```php
$identity = $client->identities->get('user_12345');

$identity->displayEmail;   // "ada@example.com"
$identity->humanityScore;  // 85
$identity->badges;         // [Badge(name: "Account Takeover Suspected", ...)]
$identity->data;           // every attribute you have sent
```

### Forcing a rescore

```php
$client->identities->analyze('user_12345');
```

This queues the work and returns; it does not wait for the cycle to finish. Dregs rescores on its own
as events arrive, so you rarely need this outside of a support or backfill flow.

## Errors

PHP's convention is `Exception`, not `Error`, so that is the suffix here: `QuotaExceededException`
where the reference SDK has `QuotaExceededError`. Everything this library throws derives from
`Dregs\Exception\DregsException`, which itself extends `RuntimeException`.

```php
use Dregs\Exception\DregsException;
use Dregs\Exception\QuotaExceededException;
use Dregs\Exception\RateLimitException;

try {
    $client->track('user.signup', identity: 'user_12345');
} catch (QuotaExceededException) {
    // Over the monthly event limit; the event was not queued.
} catch (RateLimitException $exception) {
    // Ingesting too fast. $exception->retryAfter when the server said how long.
} catch (DregsException $exception) {
    // Anything else this library throws.
}
```

| Exception | When |
| --- | --- |
| `BadRequestException` | 400, the event was malformed |
| `AuthenticationException` | 401, the secret key was not recognized |
| `QuotaExceededException` | 402, the account is over its monthly event limit |
| `PermissionDeniedException` | 403, the credential may not do this |
| `NotFoundException` | 404, no such identity, or it has not been analyzed |
| `RateLimitException` | 429, too many requests |
| `ServerException` | 5xx |
| `TimeoutException` | the request timed out |
| `ConnectionException` | the request never reached Dregs |
| `WebhookVerificationException` | an incoming webhook did not verify |

All of them live under `Dregs\Exception\`. Those that reached the API extend `ApiException` and carry
`statusCode`, `body`, and `requestId`; `getMessage()` reads `HTTP 404: Not Found (request req_abc)`,
and the bare message Dregs sent is on `description`.

Bad arguments are the exception to the rule. An empty identity, a reserved event id, or a `pk_` public
key throws PHP's own `InvalidArgumentException`, because those are programming mistakes to fix rather
than runtime conditions to handle.

### Retries

Connection failures, timeouts, 429s, and 5xx are retried automatically with exponential backoff and
full jitter, honouring `Retry-After` when the server sends one. Two retries by default:

```php
$client = new Client(maxRetries: 5);  // or 0 to handle it yourself
```

## Concurrency

This client is **synchronous**. PHP has no async story that would be idiomatic across Laravel, Symfony,
and plain FPM, so there is one client and every call blocks until Dregs answers or the retries run out.

Tracking an event is not something a user's request should wait on. Push `track()` calls onto whatever
queue your application already has — `dispatch()` in Laravel, Messenger in Symfony — and let a worker
make the call:

```php
final class TrackSignup implements ShouldQueue
{
    public function __construct(private readonly string $identity, private readonly array $attributes)
    {
    }

    public function handle(Client $dregs): void
    {
        $dregs->track('user.signup', identity: $this->identity, identityData: $this->attributes);
    }
}
```

Reads are different: `scores()` is a decision you are waiting on anyway, and it is a single fast
request. Call that one inline.

## Webhooks

Dregs signs every webhook with the channel's signing secret. Verify it against the **raw request body**
before acting on the payload — a body you decoded and re-encoded will not match, because key order and
whitespace change.

```php
use Dregs\Exception\WebhookVerificationException;
use Dregs\Webhooks;

try {
    $event = Webhooks::verify(
        $request->getContent(),
        $request->headers->get(Webhooks::SIGNATURE_HEADER) ?? '',
        $_ENV['DREGS_WEBHOOK_SECRET'],
    );
} catch (WebhookVerificationException) {
    return new Response(status: 400);
}

handle($event);
```

`verify()` also rejects payloads more than five minutes old as replays; pass `tolerance: null` to skip
that if you are deduplicating on the event id yourself. `verifySignature()` and `computeSignature()`
are there when you want the primitives. The signing secret is shown once, when you create the webhook
channel, and is not your API secret key.

## Configuration

```php
$client = new Dregs\Client(
    secretKey: null,   // defaults to $DREGS_SECRET_KEY
    baseUrl: null,     // defaults to $DREGS_BASE_URL, then https://dregs.com/api
    timeout: 10.0,     // seconds
    maxRetries: 2,
    httpClient: null,  // a Dregs\Http\Transport, or your own PSR-18 client
);
```

Leave `httpClient` alone and the SDK uses `Dregs\Http\CurlTransport`, which needs nothing installed.
Pass your application's PSR-18 client when it is configured the way your infrastructure needs — an
egress proxy, a pinned CA bundle, a shared pool, request logging.

```php
// Symfony's PSR-18 client is its own PSR-17 factory, so it needs nothing alongside it.
$client = new Dregs\Client(httpClient: new Symfony\Component\HttpClient\Psr18Client());

// Guzzle needs the factories naming.
$factory = new GuzzleHttp\Psr7\HttpFactory();

$client = new Dregs\Client(
    httpClient: new GuzzleHttp\Client(),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

`psr/http-client` and `psr/http-factory` are suggested, not required, so this costs nothing to anyone
who does not want it. When you supply a client, the timeout and the connection pool are yours rather
than ours; `timeout` only configures the transport the SDK would have built.

For anything else — a transport that records requests in tests, one that routes through a queue —
implement `Dregs\Http\Transport` and pass that.

## Static analysis

Every public class and method is annotated for PHPStan, including array shapes, so `mixed` never
escapes into your code. The SDK's own suite runs PHPStan at level `max`. Responses are `readonly` classes with typed properties; each one also keeps the
body it was built from in `raw`, so a field Dregs adds after this release is reachable without waiting
for an SDK upgrade.

Parsing is deliberately lenient. A missing field is `null`, an unrecognized score category does not
break the response, and a value of an unexpected type is ignored rather than thrown over.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The short version:

```bash
composer install
composer test   # PHPUnit
composer stan   # PHPStan
composer lint   # PHP-CS-Fixer, without changing anything
composer fix    # PHP-CS-Fixer, applying it
```

`composer.lock` is committed, and CI installs from it, so the same commands produce the same versions
locally and in CI.

## Links

- [Dregs manual](https://dregs.com/manual/) and [REST API reference](https://dregs.com/manual/api/)
- [Dregs MCP server](https://github.com/dregs-sdk/dregs-mcp), for connecting AI agents to your data
- [Security policy](SECURITY.md)

## License

MIT. See [LICENSE](LICENSE).
