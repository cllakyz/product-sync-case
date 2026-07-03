<?php

namespace App\Support;

use RuntimeException;

class RuntimeGuard
{
    /**
     * @param  array<int, string>  $arguments
     */
    public static function preventArtisanServe(array $arguments, bool $allowArtisanServe): void
    {
        if (($arguments[1] ?? null) !== 'serve') {
            return;
        }

        if ($allowArtisanServe) {
            return;
        }

        throw new RuntimeException(
            'php artisan serve is disabled for this project. Start the application with docker compose up -d --build.',
        );
    }
}
