<?php

use App\Http\Controllers\WhoController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'calendar' : 'who.index'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('who', [WhoController::class, 'index'])->name('who.index');
    Route::post('who', [WhoController::class, 'store'])->name('who.store');
});

// Every other route needs an acting user; guests are sent to /who.
Route::middleware('auth')->group(function () {
    Route::post('switch-user', [WhoController::class, 'destroy'])->name('who.destroy');

    Route::inertia('calendar', 'calendar/week')->name('calendar');
});
