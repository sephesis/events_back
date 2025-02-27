<?

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'v1'], function () {
    Route::apiResource('events', EventController::class);
   //Route::get('/events', [App\Http\Controllers\EventController::class, 'index']);
});

