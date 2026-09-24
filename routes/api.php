<?php

use App\Http\Controllers\AirportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\GateScheduleController;
use App\Http\Controllers\UnallocatedFlightController;
use Illuminate\Support\Facades\Route;

Route::apiResource('airports', AirportController::class)
    ->only(['index', 'store', 'show', 'update']);

Route::apiResource('gates', GateController::class)
    ->only(['update']);

Route::get('gate-schedule/filters', [GateScheduleController::class, 'filters']);
Route::get('gate-schedules', [GateScheduleController::class, 'index']);
Route::get('unallocated-flights/filters', [UnallocatedFlightController::class, 'filters']);
Route::get('unallocated-flights', [UnallocatedFlightController::class, 'index']);
Route::get('analytics/filters', [AnalyticsController::class, 'filters']);
Route::get('analytics', [AnalyticsController::class, 'index']);
