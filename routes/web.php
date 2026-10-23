<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\UitvoeringController;
use App\Http\Controllers\VoorJouController;
use App\Http\Controllers\WensenController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Everything else only after logging in.
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/voor-jou');

    Route::get('/bronnen', DashboardController::class)->name('sources.index');
    Route::redirect('/sources/create', '/bronnen');
    Route::post('/sources/preview', [SourceController::class, 'preview'])->name('sources.preview');
    Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
    Route::post('/sources/run', [SourceController::class, 'runAll'])->name('sources.run-all');
    Route::get('/sources/{source}', [SourceController::class, 'show'])->name('sources.show');
    Route::patch('/sources/{source}', [SourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
    Route::post('/sources/{source}/run', [SourceController::class, 'run'])->name('sources.run');

    Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

    Route::get('/analyse', [AnalysisController::class, 'index'])->name('analysis');
    Route::post('/analyse/filters', [AnalysisController::class, 'store'])->name('analysis.filters.store');
    Route::patch('/analyse/filters/{savedFilter}', [AnalysisController::class, 'update'])->name('analysis.filters.update');
    Route::delete('/analyse/filters/{savedFilter}', [AnalysisController::class, 'destroy'])->name('analysis.filters.destroy');

    Route::get('/voor-jou', [VoorJouController::class, 'index'])->name('voor-jou');
    Route::put('/voor-jou/filters', [VoorJouController::class, 'filters'])->name('voor-jou.filters');
    Route::get('/wensen', [WensenController::class, 'edit'])->name('wensen');
    Route::put('/wensen', [WensenController::class, 'update']);
    Route::post('/wensen/voorbeeld', [WensenController::class, 'preview'])->name('wensen.preview');

    Route::get('/uitvoeringen', [UitvoeringController::class, 'index'])->name('uitvoeringen');

    Route::get('/runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('runs.show');
});
