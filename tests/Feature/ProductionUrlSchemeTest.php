<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ProductionUrlSchemeTest extends TestCase
{
    public function test_urls_are_forced_to_https_in_production()
    {
        $result = Process::path(base_path())->env([
            'APP_ENV' => 'production',
            'APP_URL' => 'http://example.test',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ])->run('php artisan tinker --execute="echo url(\'/foo\');"');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringContainsString('https://example.test/foo', $result->output());
    }

    public function test_urls_stay_http_outside_production()
    {
        $result = Process::path(base_path())->env([
            'APP_ENV' => 'local',
            'APP_URL' => 'http://example.test',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ])->run('php artisan tinker --execute="echo url(\'/foo\');"');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringContainsString('http://example.test/foo', $result->output());
        $this->assertStringNotContainsString('https://example.test/foo', $result->output());
    }
}
