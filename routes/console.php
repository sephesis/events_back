<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:refresh', function () {
    $this->call('db:seed', ['--class' => \Database\Seeders\DemoDataSeeder::class, '--force' => true]);
    $this->info('Демо-мероприятия обновлены: даты сдвинуты относительно сегодняшнего дня.');
})->purpose('Пересоздать демо-набор мероприятий (фолбэк при недоступности KudaGo)');
