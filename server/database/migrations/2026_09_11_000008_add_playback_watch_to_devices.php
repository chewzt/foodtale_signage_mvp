<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->integer('play_index')->nullable()->after('clock_synced_at');
            $table->unsignedBigInteger('play_item_id')->nullable()->after('play_index');
            $table->integer('play_decoder_ms')->nullable()->after('play_item_id');
            $table->integer('play_lag_ms')->nullable()->after('play_decoder_ms');
            $table->boolean('play_playing')->nullable()->after('play_lag_ms');
            $table->timestamp('resync_until')->nullable()->after('play_playing');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'play_index',
                'play_item_id',
                'play_decoder_ms',
                'play_lag_ms',
                'play_playing',
                'resync_until',
            ]);
        });
    }
};
