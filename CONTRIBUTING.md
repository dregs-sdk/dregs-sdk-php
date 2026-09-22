# Contributing

Thanks for helping improve the Dregs PHP SDK.

This SDK is a **port of the reference SDK**, [dregs-sdk-python][python]: the TypeScript, Java,
Ruby, and PHP clients all follow that one's shape, so a change to the public surface here is a
change that has to land in all five. Surface changes are worth discussing in an issue before you
write the code.

[python]: https://github.com/dregs-sdk/dregs-sdk-python

## Getting set up

You need PHP 8.2 or newer and [Composer](https://getcomposer.org/). The dependency set is locked,
so an install reproduces exactly what CI runs.

```bash
composer install
```

Then:

```bash
composer test   # PHPUnit
composer stan   # PHPStan
composer lint   # PHP-CS-Fixer, reporting without changing anything
composer fix    # PHP-CS-Fixer, applying it
```

CI runs exactly these, against the same locked versions, so a green run locally means a green run
there. Tests run on PHP 8.2, 8.3, and 8.4.

If you change a dependency in `composer.json`, commit the resulting `composer.lock` alongside it.
CI runs `composer validate --strict`, which fails when the two have drifted apart.

## What we look for

- **Tests.** The suite mocks HTTP behind `Dregs\Http\Transport`, so tests are fast and reach no
  network. New behavior needs a test; a bug fix needs one that fails without it.
- **Types.** PHPStan runs at level `max` over `src`, `tests`, and `examples`. Annotate array
  shapes: a caller should never have to narrow a `mixed` that came out of this SDK.
- **No required runtime dependencies.** `new Dregs\Client($key)` has to work in a project with
  nothing else installed. PSR-18 support stays a `suggest`, and a test asserts that `require`
  holds nothing but `php` and extensions.
- **Lenient parsing.** Models tolerate fields they do not recognize and keep the raw body in
  `raw`. An SDK that throws on a response it half-understands ages badly.
- **Doc comments that say something.** Every public class and method carries one, and it explains
  what the argument is for or what the caller should do about an error rather than restating the
  signature.

## The API this wraps

The [Dregs manual](https://dregs.com/manual/api/) is the source of truth for the REST API. If this
SDK disagrees with the manual, the manual wins; please say so in your pull request so both get
fixed.

## Reporting problems

Bugs and feature requests go to
[GitHub issues](https://github.com/dregs-sdk/dregs-sdk-php/issues). Security reports go to
[security@dregs.com](mailto:security@dregs.com) instead — see [SECURITY.md](SECURITY.md).
Questions about your account or the service go to [support@dregs.com](mailto:support@dregs.com).
