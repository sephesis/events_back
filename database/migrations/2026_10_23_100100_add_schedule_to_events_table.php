<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Часы работы для мест/выставок (kind=open) и длительность посещения —
            // нужны для проверки временных окон при построении маршрута.
            $table->time('opens_at')->nullable()->after('ends_at');
            $table->time('closes_at')->nullable()->after('opens_at');
            $table->unsignedSmallInteger('duration_min')->nullable()->after('closes_at');

            $table->index(['source', 'city_code']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['source', 'city_code']);
            $table->dropIndex(['starts_at']);
            $table->dropColumn(['opens_at', 'closes_at', 'duration_min']);
        });
    }
};
