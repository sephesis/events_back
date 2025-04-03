<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {
    Route::get('/events', [App\Http\Controllers\EventController::class, 'index']);
    Route::get('/categories', [App\Http\Controllers\CategoryController::class, 'index']);
});
