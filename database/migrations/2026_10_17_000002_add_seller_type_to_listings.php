<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // From the ad itself, every run.
            $table->string('dealer_slug')->nullable()->after('provincie')->index();
            $table->string('importeur_portaal')->nullable()->after('dealer_slug');
            // Name and province "$undefined": how Gaspedaal shows a private seller.
            $table->boolean('zonder_aanbieder')->default(false)->after('importeur_portaal');

            // From Gaspedaal's own filters (vrk, grn, btw), run alongside each source.
            $table->boolean('particulier')->nullable()->after('zonder_aanbieder');
            $table->string('garantie', 20)->nullable()->after('particulier');
            $table->boolean('btw_verrekenbaar')->nullable()->after('garantie');
            $table->date('kenmerken_op')->nullable()->after('btw_verrekenbaar');

            // particulier, merkdealer or handelaar; derived from all of the above (App\Gaspedaal\SellerType).
            $table->string('aanbieder_type', 20)->nullable()->after('kenmerken_op')->index();
        });

        Schema::table('scrape_pages', function (Blueprint $table) {
            // Null for the search itself; e.g. "grn=MERKGARANTIE" for a filtered pass.
            $table->string('facet', 40)->nullable()->after('page_number');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['dealer_slug']);
            $table->dropIndex(['aanbieder_type']);
            $table->dropColumn(['dealer_slug', 'importeur_portaal', 'zonder_aanbieder', 'particulier', 'garantie', 'btw_verrekenbaar', 'kenmerken_op', 'aanbieder_type']);
        });

        Schema::table('scrape_pages', function (Blueprint $table) {
            $table->dropColumn('facet');
        });
    }
};
