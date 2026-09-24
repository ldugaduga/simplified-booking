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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->dateTime('start_at'); // stored in UTC
            $table->dateTime('end_at');   // stored in UTC
            $table->string('status')->default('confirmed');
            $table->string('name');
            $table->string('email');
            $table->text('notes')->nullable();
            $table->string('manage_token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Equals start_at while the appointment is active and NULL once it is
            // cancelled, so the unique index blocks two live bookings for the same
            // slot on SQLite, MySQL and Postgres alike.
            $table->dateTime('slot_lock')->nullable()->unique();
            $table->timestamps();

            $table->index(['start_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
