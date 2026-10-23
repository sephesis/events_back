<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city_code');
            $table->decimal('budget', 10, 2)->nullable();
            $table->decimal('total_distance_km', 8, 3)->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itineraries');
    }
};
