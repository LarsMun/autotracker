<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One rapportcijfer (1–10) per car per day instead of a match, deal and total score of 0–100;
     * deelscores now holds the grade per vak. Earlier days keep their rang and lose their scores.
     */
    public function up(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->decimal('cijfer', 3, 1)->nullable()->after('afgewezen');
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropColumn(['matchscore', 'dealscore_score', 'totaalscore']);
        });

        // Their 0–100 subscores mean nothing next to grades.
        DB::table('match_scores')->update(['deelscores' => null]);
    }

    public function down(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->decimal('matchscore', 5, 1)->nullable()->after('afgewezen');
            $table->decimal('dealscore_score', 5, 1)->nullable()->after('matchscore');
            $table->decimal('totaalscore', 5, 1)->nullable()->after('dealscore_score');
        });

        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropColumn('cijfer');
        });
    }
};
