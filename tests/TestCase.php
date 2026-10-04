<?php

namespace Threadwire\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Threadwire\Facades\Threadwire;
use Threadwire\ThreadwireServiceProvider;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    protected function getPackageProviders($app): array
    {
        return [ThreadwireServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Threadwire' => Threadwire::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('threadwire.api_key', 'test-key');
        $app['config']->set('threadwire.url', 'https://threadwire.test/api/v1');
        $app['config']->set('threadwire.webhook.secret', self::SECRET);
    }
}
