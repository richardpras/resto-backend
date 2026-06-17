<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesQrGuestSession;

abstract class TestCase extends BaseTestCase
{
    use CreatesQrGuestSession;
}
