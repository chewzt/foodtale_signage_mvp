<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->string('kind', 20)->default('playlist')->after('name');
            $table->unsignedTinyInteger('panel_count')->default(1)->after('kind');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('panel_index')->default(0)->after('playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['kind', 'panel_count']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('panel_index');
        });
    }
};
