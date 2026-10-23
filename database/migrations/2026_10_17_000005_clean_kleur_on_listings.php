<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Next.js wrote a missing colour as "$undefined"; the Recorder now stores null.
        DB::table('listings')->where('kleur', '$undefined')->update(['kleur' => null]);

        // Filtered on in the analysis and in the wensen.
        Schema::table('listings', fn ($table) => $table->index('kleur'));
    }

    public function down(): void
    {
        Schema::table('listings', fn ($table) => $table->dropIndex(['kleur']));
    }
};
