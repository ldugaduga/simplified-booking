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
        // Weekly working hours, in the admin's timezone. Several rows per
        // weekday are allowed (e.g. 09:00-12:00 and 13:00-17:00).
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday ... 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index('weekday');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
