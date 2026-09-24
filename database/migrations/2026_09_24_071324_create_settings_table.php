<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-row table holding the booking rules.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('timezone')->default('UTC');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedSmallInteger('min_notice_hours')->default(4);
            $table->unsignedSmallInteger('max_days_ahead')->default(60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
