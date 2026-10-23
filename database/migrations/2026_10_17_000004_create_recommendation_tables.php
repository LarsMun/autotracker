<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // price = a + b·age_months + c·km, per model, per brand and over all cars ("alle").
        Schema::create('model_factors', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10);
            $table->string('merk')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('n');
            $table->double('a');
            $table->double('b_per_maand');
            $table->double('c_per_km');
            $table->double('r2');
            $table->date('computed_on');

            $table->unique(['scope', 'merk', 'model']);
        });

        // Per car per day: what comparable cars say it should cost.
        Schema::create('deal_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('vraagprijs')->nullable();
            $table->unsignedInteger('verwachte_prijs')->nullable();
            $table->integer('deal_eur')->nullable();
            $table->decimal('deal_pct', 6, 2)->nullable();
            $table->unsignedSmallInteger('vergelijkbaar_n')->default(0);
            // 0 = the strict criteria; higher = widened that many steps; null = too few even then.
            $table->unsignedTinyInteger('verruiming')->nullable();
            // [{id, prijs, gecorrigeerd}]
            $table->json('vergelijkbaar')->nullable();
            $table->string('factor_scope', 10)->nullable();
            $table->boolean('check_dit')->default(false);

            $table->unique(['listing_id', 'date']);
            $table->index('date');
        });

        // Per car per day: how well it fits the wensen, and its place in the ranking.
        Schema::create('match_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('door_eisen');
            $table->json('afgewezen')->nullable();
            $table->decimal('matchscore', 5, 1)->nullable();
            $table->decimal('dealscore_score', 5, 1)->nullable();
            $table->decimal('totaalscore', 5, 1)->nullable();
            $table->json('deelscores')->nullable();
            $table->unsignedInteger('rang')->nullable();

            $table->unique(['listing_id', 'date']);
            $table->index(['date', 'rang']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::table('listings', function (Blueprint $table) {
            // datum_geplaatst when that is plausible, else first_seen (App\Deals\OnlineSince).
            $table->date('online_sinds')->nullable()->after('datum_geplaatst');
            $table->string('online_sinds_bron', 20)->nullable()->after('online_sinds');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['online_sinds', 'online_sinds_bron']);
        });
        Schema::dropIfExists('settings');
        Schema::dropIfExists('match_scores');
        Schema::dropIfExists('deal_scores');
        Schema::dropIfExists('model_factors');
    }
};
