<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type');
            $table->timestamp('scheduled_time')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->string('status')->default('pending');
            $table->string('type');
            $table->timestamps();

            $table->index(['status', 'queue_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
