<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('panel_index')->nullable()->after('sort_order');
        });

        $carousels = DB::table('playlists')->where('kind', 'carousel')->get(['id']);
        foreach ($carousels as $carousel) {
            $items = DB::table('playlist_items')
                ->where('playlist_id', $carousel->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id']);
            foreach ($items->values() as $i => $item) {
                DB::table('playlist_items')->where('id', $item->id)->update([
                    'panel_index' => $i,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropColumn('panel_index');
        });
    }
};
