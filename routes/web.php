<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Sync dashboard — the page fetches its data from the JSON API (Sanctum SPA
    // cookie auth), so a static Inertia route with no controller is enough.
    Route::inertia('sync', 'sync/dashboard')->name('sync.dashboard');
});

require __DIR__.'/settings.php';
