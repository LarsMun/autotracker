<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // From App\Uitrusting\Classifier and config/uitrusting.php.
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedTinyInteger('comfort_niveau')->nullable()->after('aanbieder_type')->index();
            $table->boolean('sport')->nullable()->after('comfort_niveau')->index();
            $table->string('uitvoeringslijn', 100)->nullable()->after('sport');
            $table->json('uitrusting_opties')->nullable()->after('uitvoeringslijn');
            // The version of config/uitrusting.php it was classified with.
            $table->char('uitrusting_versie', 32)->nullable()->after('uitrusting_opties');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['comfort_niveau']);
            $table->dropIndex(['sport']);
            $table->dropColumn(['comfort_niveau', 'sport', 'uitvoeringslijn', 'uitrusting_opties', 'uitrusting_versie']);
        });
    }
};
