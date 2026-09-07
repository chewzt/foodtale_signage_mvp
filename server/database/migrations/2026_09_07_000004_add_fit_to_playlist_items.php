<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->string('fit', 16)->default('loop')->after('duration_ms');
            $table->unsignedInteger('file_duration_ms')->nullable()->after('fit');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn(['fit', 'file_duration_ms']);
        });
    }
};
