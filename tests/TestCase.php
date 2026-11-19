<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Dipanggil RefreshDatabase tepat sebelum migrate:fresh
    protected function beforeRefreshingDatabase()
    {
        $name = DB::connection()->getDatabaseName();

        if ($name !== 'manet_test') {
            throw new \RuntimeException("Test menolak berjalan di database '{$name}'. Hanya manet_test yang diizinkan.");
        }
    }
}