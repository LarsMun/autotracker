<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Titles in which no uitvoeringslijn was recognised, to see what config/uitrusting.php misses.
 * For information only.
 */
class UitvoeringController extends Controller
{
    private const int TITLES_PER_MODEL = 30;

    public function index(): Response
    {
        $totals = DB::table('listings')
            ->selectRaw('merk, model, count(*) as totaal, sum(case when uitvoeringslijn is null then 1 else 0 end) as niet_herkend')
            ->groupBy('merk', 'model')
            ->orderBy('merk')->orderBy('model')
            ->get();

        $titles = DB::table('listings')
            ->whereNull('uitvoeringslijn')
            ->selectRaw('merk, model, uitvoering, count(*) as aantal')
            ->groupBy('merk', 'model', 'uitvoering')
            ->orderByDesc('aantal')->orderBy('uitvoering')
            ->get()
            ->groupBy(fn ($row) => "{$row->merk}|{$row->model}");

        return Inertia::render('Uitvoeringen/Index', [
            'models' => $totals->map(fn ($m) => [
                'merk' => $m->merk,
                'model' => $m->model,
                'totaal' => (int) $m->totaal,
                'niet_herkend' => (int) $m->niet_herkend,
                'titels' => ($titles["{$m->merk}|{$m->model}"] ?? collect())
                    ->take(self::TITLES_PER_MODEL)
                    ->map(fn ($t) => ['uitvoering' => $t->uitvoering, 'aantal' => (int) $t->aantal])
                    ->values(),
            ])->values(),
            'per_model' => self::TITLES_PER_MODEL,
        ]);
    }
}
