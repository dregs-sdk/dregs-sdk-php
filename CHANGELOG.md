# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Unreleased

The first stable release. It catches the SDK up with the public API: groups, the rest of the identity
reads and writes, events, devices, and escalations, with paging throughout. Nothing from 0.1.0 changes,
so upgrading needs no code changes.

### Added

- `groups` on `track()`: the organizations, teams, workspaces, or other groups the user is acting in,
  each with an `id`, an optional `type` (`'organization'` by default), and optional `data`. Dregs makes
  the identity a member of each.
- `$client->groups`: `list()`, `listAll()`, `get()`, `create()`, `update()`, `addMember()`, and
  `removeMember()`.
- `identities->list()`, `listAll()`, `update()` (to disregard an identity or merge attributes),
  `history()`, `links()`, `reviews()`, and `review()`, and a `cycleId` on `analysis()` for a past cycle.
- `$client->events`: `list()`, `listAll()`, and `get()`, with the groups each event was sent with.
- `$client->devices`: `list()`, `listAll()`, `get()`, and `update()` to disregard a device.
- `$client->escalations`: `list()`, `listAll()`, `get()`, `update()` to move an escalation along, and
  `summary()`.
- `Dregs\Model\Page`, a countable, iterable page of items with its position and totals, and a
  `listAll()` on every namespace that walks the pages for you as a `Generator`.

### Fixed

- An error response whose body carries no message now reports the HTTP reason phrase, such as
  `HTTP 404: Not Found`, instead of `Request failed`.

## [0.1.0] - 2026-09-22

The first release. A server-side client for tracking events and reading scores.

### Added

- `Dregs\Client`, authenticating with a credential's `sk_` secret key against a configurable base
  URL, with no required Composer dependencies.
- `track()` for recording backend events against an identity, always sending an idempotency `id`
  so a retry cannot double-count.
- `identities->get()`, `identities->scores()`, `identities->analysis()`, and
  `identities->analyze()`.
- Typed exceptions for 400, 401, 402, 403, 404, 429, and 5xx, plus connection and timeout
  failures, all deriving from `Dregs\Exception\DregsException`.
- Automatic retries with exponential backoff and full jitter, honouring `Retry-After`.
- `Dregs\Webhooks::verify()` for checking a webhook's signature and rejecting replays.
- `readonly` models with a `raw` escape hatch, annotated for PHPStan.
- `Dregs\Http\CurlTransport` by default and `Dregs\Http\Psr18Transport` for applications that
  would rather send through their own PSR-18 client.

[1.0.0]: https://github.com/dregs-sdk/dregs-sdk-php/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/dregs-sdk/dregs-sdk-php/releases/tag/v0.1.0
