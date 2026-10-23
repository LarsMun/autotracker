<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The filters are applied when "Voor jou" is shown (App\Recommend\Ranking), so whether a car
     * passes them and its place are no longer stored per day.
     */
    public function up(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropIndex(['date', 'rang']);
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropColumn(['door_eisen', 'afgewezen', 'rang']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropIndex(['date']);
            $table->boolean('door_eisen')->default(false)->after('date');
            $table->json('afgewezen')->nullable()->after('door_eisen');
            $table->unsignedInteger('rang')->nullable();
            $table->index(['date', 'rang']);
        });
    }
};
