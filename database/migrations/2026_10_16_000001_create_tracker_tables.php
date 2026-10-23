<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Gaspedaal search: every page of its results is fetched once a day.
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 1000);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->timestamps();
        });

        // One row per car (advertentieId), with its latest properties.
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertentie_id')->unique();
            $table->string('merk')->nullable();
            $table->string('model')->nullable();
            $table->string('uitvoering', 500)->nullable();
            $table->string('brandstof')->nullable();
            $table->string('transmissie')->nullable();
            $table->string('carrosserie')->nullable();
            $table->string('kleur')->nullable();
            $table->unsignedTinyInteger('deuren')->nullable();
            $table->unsignedSmallInteger('bouwjaar')->nullable();
            $table->unsignedInteger('motorinhoud')->nullable();
            $table->unsignedSmallInteger('vermogen_kw')->nullable();
            $table->string('aanbieder')->nullable();
            $table->string('plaats')->nullable();
            $table->string('provincie', 10)->nullable();
            $table->string('foto_url', 1000)->nullable();
            $table->string('foto_path')->nullable();
            $table->dateTime('datum_geplaatst')->nullable();
            $table->unsignedInteger('prijs')->nullable();
            $table->unsignedInteger('kilometerstand')->nullable();
            $table->date('first_seen');
            $table->date('last_seen');
            // Set when no source it belongs to has it any more (after complete runs only).
            $table->date('gone_at')->nullable()->index();
            $table->timestamps();

            $table->index(['merk', 'model']);
        });

        // Which source found which car; a car can be in several sources but is one listing.
        Schema::create('listing_source', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->date('first_seen');
            $table->date('last_seen');
            // The date of the first complete run of this source that no longer had the car.
            $table->date('gone_at')->nullable();

            $table->unique(['source_id', 'listing_id']);
            $table->index(['source_id', 'gone_at']);
            $table->index(['source_id', 'first_seen']);
        });

        // The raw occasion JSON, stored again only when it changed. Which version a car had on a
        // given day: observations.snapshot_id.
        Schema::create('listing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->char('hash', 40);
            $table->longText('data');
            $table->date('first_seen');
            $table->date('last_seen');

            $table->unique(['listing_id', 'hash']);
        });

        // One row per car per day it was seen: price and km on that day.
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('prijs')->nullable();
            $table->unsignedInteger('kilometerstand')->nullable();
            $table->foreignId('snapshot_id')->nullable()->constrained('listing_snapshots')->nullOnDelete();
            $table->timestamp('seen_at');

            $table->unique(['listing_id', 'date']);
            $table->index('date');
        });

        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            // running, success, incomplete (data kept, nothing marked gone), failed
            $table->string('status', 20)->default('running');
            $table->string('trigger', 20)->default('schedule');
            $table->string('url', 1000);
            $table->unsignedInteger('total_reported')->nullable();
            $table->unsignedSmallInteger('pages_expected')->nullable();
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->unsignedInteger('listings_seen')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('gone_count')->default(0);
            $table->text('error')->nullable();
            $table->text('warnings')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'run_date']);
        });

        // Every fetched page; the HTML itself is on disk (gzipped), so everything can be parsed again.
        Schema::create('scrape_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('occasions_count')->nullable();
            $table->boolean('ld_matches')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->index(['scrape_run_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_pages');
        Schema::dropIfExists('scrape_runs');
        Schema::dropIfExists('observations');
        Schema::dropIfExists('listing_snapshots');
        Schema::dropIfExists('listing_source');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('sources');
    }
};
