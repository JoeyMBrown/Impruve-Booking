<?php

use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentStatusController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\ProposalController;
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

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('api/calendar/week', [CalendarController::class, 'week'])->name('api.calendar.week');

    // No destroy: equipment is retired through its status, never deleted.
    Route::resource('equipment', EquipmentController::class)->except('destroy');
    Route::put('equipment/{equipment}/status', [EquipmentStatusController::class, 'update'])->name('equipment.status');
    Route::get('api/equipment/{equipment}/status-impact', [EquipmentStatusController::class, 'impact'])->name('api.equipment.impact');

    Route::get('bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');
    Route::get('bookings/{booking}/edit', [BookingController::class, 'edit'])->name('bookings.edit');
    Route::put('bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::get('my-bookings', MyBookingsController::class)->name('bookings.mine');
    Route::get('api/availability', AvailabilityController::class)->name('api.availability');

    Route::post('chat/conversations', [ChatController::class, 'start'])->name('chat.start');
    Route::post('chat/conversations/{conversation}/messages', [ChatController::class, 'message'])
        ->middleware('throttle:chat')
        ->name('chat.message');
    Route::post('chat/proposals/{proposal}/confirm', [ProposalController::class, 'confirm'])->name('chat.proposals.confirm');
    Route::post('chat/proposals/{proposal}/dismiss', [ProposalController::class, 'dismiss'])->name('chat.proposals.dismiss');
});
