<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('city_code');
            $table->string('category_group')->nullable();
            $table->string('title');
            $table->string('short_title')->nullable();
            $table->text('description')->nullable();
            $table->string('tagline')->nullable();
            $table->string('place_name')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            $table->boolean('is_free')->default(false);
            $table->string('age_restriction')->nullable();
            $table->json('images')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['city_code', 'category_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
