<?php

namespace Tests;

use App\Services\Migration\StagingBackupService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $env = strtolower((string) config('app.env'));
        $dbName = strtolower((string) DB::connection()->getDatabaseName());

        if ($env === 'production' || in_array($dbName, StagingBackupService::PROTECTED_DATABASES, true)) {
            throw new RuntimeException("ABORTED TEST SUITE: Unsafe database [{$dbName}] or environment [{$env}].");
        }
    }

    /**
     * Preserve exact trailing slashes in test URIs (Laravel's default prepareUrlForRequest trims '/').
     */
    protected function prepareUrlForRequest($uri): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        $root = rtrim((string) config('app.url', 'http://localhost:8000'), '/');
        if ($uri === '' || $uri === '/') {
            return $root . '/';
        }

        return $root . '/' . ltrim($uri, '/');
    }
}
