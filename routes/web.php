<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public booking page (calendar + slot picker arrive in milestone 3).
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('/', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
