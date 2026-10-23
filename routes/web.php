<?php

use Illuminate\Support\Facades\Route;

// Бэкенд — только API; корень сайта ведёт на его описание.
Route::redirect('/', '/api/v1');
