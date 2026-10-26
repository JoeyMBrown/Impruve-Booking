<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PHP tests must not depend on a frontend build (CI runs them in a separate job).
        $this->withoutVite();
    }
}
