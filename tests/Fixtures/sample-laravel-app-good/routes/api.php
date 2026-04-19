<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function () {
    Route::get('/users', fn () => ['ok' => true]);
    Route::post('/users', fn () => ['ok' => true]);
});
