<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Явно указанные интересы (slug категорий) — основа холодного старта.
            $table->json('interests')->nullable()->after('password');
            $table->decimal('default_budget', 10, 2)->nullable()->after('interests');
            $table->string('home_city_code')->nullable()->after('default_budget');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['interests', 'default_budget', 'home_city_code']);
        });
    }
};
