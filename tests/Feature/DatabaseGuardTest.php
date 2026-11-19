<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseGuardTest extends TestCase
{
    public function test_tests_do_not_use_the_real_database(): void
    {
        $name = DB::connection()->getDatabaseName();
        fwrite(STDERR, "\nDB aktif saat test: {$name}\n");

        $this->assertNotSame('manet_db', $name);
        $this->assertSame('manet_test', $name);
    }
}