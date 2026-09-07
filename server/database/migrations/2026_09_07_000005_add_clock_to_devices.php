<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->integer('clock_offset_ms')->nullable()->after('last_seen_at');
            $table->unsignedInteger('clock_rtt_ms')->nullable()->after('clock_offset_ms');
            $table->timestamp('clock_synced_at')->nullable()->after('clock_rtt_ms');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['clock_offset_ms', 'clock_rtt_ms', 'clock_synced_at']);
        });
    }
};
