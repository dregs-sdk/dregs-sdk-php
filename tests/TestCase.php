<?php

declare(strict_types=1);

namespace Dregs\Tests;

use Dregs\Client;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * The base for every test in the suite.
 *
 * Its one job is keeping a developer's own `DREGS_*` variables out of the tests, which would
 * otherwise quietly change what the configuration tests are asserting about.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected const BASE_URL = 'https://api.test.invalid/api';

    protected const SECRET_KEY = 'sk_abcdefghQijklmQabcdefghijklmn';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();

        parent::tearDown();
    }

    /**
     * Sets an environment variable everywhere the SDK looks for one.
     */
    protected function setEnvironment(string $name, string $value): void
    {
        putenv("{$name}={$value}");

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearEnvironment(): void
    {
        foreach ([Client::SECRET_KEY_ENV, Client::BASE_URL_ENV] as $name) {
            putenv($name);

            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
