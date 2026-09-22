<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use PHPUnit\Framework\Assert;

/**
 * The package metadata, and the things that have to agree with each other.
 *
 * The version appears in three places — the constant, the changelog, and the release tag the
 * publish workflow checks — and a release where they disagree publishes something other than
 * what the notes describe. Two of the three are checkable here.
 */
final class PackageTest extends TestCase
{
    public function testTheVersionLooksLikeASemanticVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', Client::VERSION);
    }

    public function testTheChangelogDocumentsThisVersion(): void
    {
        self::assertStringContainsString(
            '## [' . Client::VERSION . ']',
            $this->read('CHANGELOG.md'),
            'The changelog has no section for the version the SDK reports.'
        );
    }

    public function testThePackageIsNamedAsPackagistWillSeeIt(): void
    {
        self::assertSame('dregs/dregs-sdk', $this->composer()['name'] ?? null);
        self::assertSame('MIT', $this->composer()['license'] ?? null);
    }

    public function testThePackageHasNoRequiredRuntimeDependencies(): void
    {
        $require = $this->composer()['require'] ?? null;

        if (!is_array($require)) {
            self::fail('composer.json declares no require section.');
        }

        // Extensions are fine; a Composer package is not. Installing this SDK should never
        // drag an HTTP client into somebody else's dependency tree.
        foreach (array_keys($require) as $package) {
            self::assertMatchesRegularExpression(
                '/^(php|ext-[a-z0-9]+)$/',
                (string) $package,
                "'{$package}' would become a required runtime dependency."
            );
        }
    }

    public function testThePackageSupportsTheVersionsCiTests(): void
    {
        $require = $this->composer()['require'] ?? null;

        if (!is_array($require)) {
            self::fail('composer.json declares no require section.');
        }

        self::assertSame('>=8.2', $require['php'] ?? null);
    }

    public function testTheAutoloaderMapsTheNamespaceToSrc(): void
    {
        $autoload = $this->composer()['autoload'] ?? null;

        if (!is_array($autoload)) {
            self::fail('composer.json declares no autoload section.');
        }

        $psr4 = $autoload['psr-4'] ?? null;

        if (!is_array($psr4)) {
            self::fail('composer.json declares no PSR-4 mapping.');
        }

        self::assertSame('src/', $psr4['Dregs\\'] ?? null);
    }

    public function testTheLockFileIsCommittedAlongsideIt(): void
    {
        self::assertFileExists($this->path('composer.lock'));
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $decoded = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            self::fail('composer.json is not a JSON object.');
        }

        $fields = [];

        foreach ($decoded as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    private function read(string $name): string
    {
        $contents = file_get_contents($this->path($name));

        if ($contents === false) {
            Assert::fail("Could not read {$name}.");
        }

        return $contents;
    }

    private function path(string $name): string
    {
        return dirname(__DIR__) . '/' . $name;
    }
}
