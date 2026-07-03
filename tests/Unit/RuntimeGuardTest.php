<?php

namespace Tests\Unit;

use App\Support\RuntimeGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RuntimeGuardTest extends TestCase
{
    public function test_it_blocks_the_artisan_serve_command(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php artisan serve is disabled');

        RuntimeGuard::preventArtisanServe(['artisan', 'serve'], false);
    }

    public function test_it_allows_other_artisan_commands(): void
    {
        RuntimeGuard::preventArtisanServe(['artisan', 'test'], false);

        $this->addToAssertionCount(1);
    }

    public function test_it_can_be_explicitly_allowed(): void
    {
        RuntimeGuard::preventArtisanServe(['artisan', 'serve'], true);

        $this->addToAssertionCount(1);
    }
}
