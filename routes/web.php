<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/airports', function () {
    return view('app');
});

Route::get('/airports/{airport}', function () {
    return view('app');
})->whereNumber('airport');

Route::get('/gate-schedule', function () {
    return view('app');
});

Route::get('/unallocated-flights', function () {
    return view('app');
});

Route::get('/analytics', function () {
    return view('app');
});
