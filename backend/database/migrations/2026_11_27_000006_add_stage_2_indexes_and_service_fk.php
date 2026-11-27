<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();

            $table->index('customer_id');
            $table->index('barber_id');
            $table->index('service_id');
        });

        Schema::table('queue_logs', function (Blueprint $table) {
            $table->index('appointment_id');
        });

        DB::statement("CREATE UNIQUE INDEX appointments_active_online_barber_slot_unique ON appointments (barber_id, scheduled_time) WHERE type = 'online' AND status IN ('pending', 'confirmed') AND barber_id IS NOT NULL AND scheduled_time IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointments_active_online_barber_slot_unique');

        Schema::table('queue_logs', function (Blueprint $table) {
            $table->dropIndex(['appointment_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['barber_id']);
            $table->dropIndex(['service_id']);
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
