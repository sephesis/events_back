<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {
    Route::get('/events', [App\Http\Controllers\EventController::class, 'index']);
    Route::get('/categories', [App\Http\Controllers\CategoryController::class, 'index']);


    Route::prefix('auth')->group(function () {
        Route::post('register', [App\Http\Controllers\AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [App\Http\Controllers\AuthController::class, 'login'])->name('auth.login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [App\Http\Controllers\AuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [App\Http\Controllers\AuthController::class, 'me'])->name('auth.me');
        });
    });

    Route::post('/itineraries/build', [App\Http\Controllers\ItineraryController::class, 'build']);
    Route::post('/route-line', [App\Http\Controllers\MapController::class, 'routeLine']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/itineraries/recommendations', [App\Http\Controllers\ItineraryController::class, 'recommendations']);
        Route::get('/itineraries', [App\Http\Controllers\ItineraryController::class, 'index']);
        Route::post('/itineraries', [App\Http\Controllers\ItineraryController::class, 'store']);
        Route::get('/itineraries/{itinerary}', [App\Http\Controllers\ItineraryController::class, 'show']);
        Route::delete('/itineraries/{itinerary}', [App\Http\Controllers\ItineraryController::class, 'destroy']);
    });
});
