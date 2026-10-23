<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show'])->whereNumber('event');
    Route::get('/categories', [CategoryController::class, 'index']);

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    // Доступны гостям; если передан токен — результат персонализируется.
    Route::post('/itineraries/build', [ItineraryController::class, 'build']);
    Route::post('/itineraries/plan', [ItineraryController::class, 'plan']);
    Route::post('/recommendations', [RecommendationController::class, 'recommend']);
    Route::get('/recommendations/model', [RecommendationController::class, 'model']);
    Route::post('/route-line', [MapController::class, 'routeLine']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/itineraries/recommendations', [ItineraryController::class, 'recommendations']);
        Route::get('/itineraries', [ItineraryController::class, 'index']);
        Route::post('/itineraries', [ItineraryController::class, 'store']);
        Route::get('/itineraries/{itinerary}', [ItineraryController::class, 'show']);
        Route::delete('/itineraries/{itinerary}', [ItineraryController::class, 'destroy']);

        Route::post('/interactions', [InteractionController::class, 'store']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::delete('/profile/history', [ProfileController::class, 'clearHistory']);
        Route::delete('/profile', [ProfileController::class, 'destroy']);
    });
});
