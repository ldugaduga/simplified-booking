<?php

use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\BlockedDateController;
use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [BookingController::class, 'show'])->name('home');
Route::get('slots', [BookingController::class, 'slots'])->middleware('throttle:60,1')->name('booking.slots');
Route::post('book', [BookingController::class, 'store'])->name('booking.store');
Route::get('book/confirmed/{appointment}', [BookingController::class, 'confirmed'])->middleware('signed')->name('booking.confirmed');

Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('/', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('availability', [AvailabilityController::class, 'edit'])->name('admin.availability.edit');
    Route::put('availability/hours', [AvailabilityController::class, 'updateHours'])->name('admin.availability.hours.update');
    Route::put('availability/rules', [AvailabilityController::class, 'updateRules'])->name('admin.availability.rules.update');

    Route::post('blocked-dates', [BlockedDateController::class, 'store'])->name('admin.blocked-dates.store');
    Route::delete('blocked-dates/{blockedDate}', [BlockedDateController::class, 'destroy'])->name('admin.blocked-dates.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
