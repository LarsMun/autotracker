<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enough comparable cars, but the corrected expected price fell outside a plausible range
        // around their asking prices: no deal score ("onvoldoende betrouwbaar").
        Schema::table('deal_scores', function (Blueprint $table) {
            $table->boolean('onbetrouwbaar')->default(false)->after('check_dit');
        });

        // The CDN answered 4xx for the photo: not tried again, the page shows "geen foto".
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('foto_onbeschikbaar_at')->nullable()->after('foto_path');
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $table) => $table->dropColumn('foto_onbeschikbaar_at'));
        Schema::table('deal_scores', fn (Blueprint $table) => $table->dropColumn('onbetrouwbaar'));
    }
};
