<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the URL changes: the next complete run unlinks the cars that no longer match,
        // instead of counting them as gone.
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('rebase_pending')->default(false)->after('active');
        });

        // Sources whose URL was changed before this existed: their URL differs from their first run's.
        foreach (DB::table('sources')->get() as $source) {
            $first = DB::table('scrape_runs')->where('source_id', $source->id)->orderBy('id')->value('url');

            if ($first !== null && $first !== $source->url) {
                DB::table('sources')->where('id', $source->id)->update(['rebase_pending' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('rebase_pending');
        });
    }
};
