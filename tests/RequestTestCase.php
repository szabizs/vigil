<?php

namespace Materom\Vigil\Tests;

/**
 * Boots the application as an HTTP request (VIGIL_FORCE_REQUEST) so the request hooks are registered.
 */
abstract class RequestTestCase extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['VIGIL_FORCE_REQUEST'] = '1';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['VIGIL_FORCE_REQUEST']);
    }
}
