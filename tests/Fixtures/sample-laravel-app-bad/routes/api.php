<?php

use Illuminate\Support\Facades\Route;

Route::get('/users', fn () => ['ok' => true]);
Route::post('/users', fn () => ['ok' => true]);
