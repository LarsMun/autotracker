<?php

namespace App\Http\Controllers;

use App\Jobs\RecomputeMatches;
use App\Models\Listing;
use App\Recommend\Reasons;
use App\Recommend\Wensen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VoorJouController extends Controller
{
    public const int TOP = 10;

    /** The last chosen make and model filter, in the settings table. */
    public const string FILTERS_KEY = 'voor_jou_filters';

    public function index(Request $request): Response|RedirectResponse
    {
        // Without merk or model in the URL: the last chosen ones. "?merk=" means no filter.
        if (! $request->has('merk') && ! $request->has('model')) {
            $saved = self::filtersFrom(json_decode((string) DB::table('settings')->where('key', self::FILTERS_KEY)->value('value'), true) ?? []);

            if ($saved['merk'] !== '') {
                return redirect()->route('voor-jou', array_filter($saved) + $request->only('page'));
            }
        }

        $filters = self::filtersFrom($request->query());
        $this->remember($filters);

        $day = DB::table('match_scores')->max('date');
        $wensen = Wensen::get();

        if ($day === null) {
            return Inertia::render('VoorJou/Index', ['day' => null, 'ranking' => null, 'top' => null, 'gewichten' => Wensen::weights($wensen), 'filters' => $filters, 'options' => [], 'bijwerken' => RecomputeMatches::pending()]);
        }

        $merken = array_filter(explode(',', $filters['merk']));
        $modellen = collect(array_filter(explode(',', $filters['model'])))
            ->map(fn ($m) => explode('|', $m, 2))->groupBy(0)->map(fn ($pairs) => $pairs->pluck(1)->all());

        $ranking = DB::table('match_scores as m')
            ->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->leftJoin('deal_scores as d', fn ($j) => $j->on('d.listing_id', '=', 'm.listing_id')->on('d.date', '=', 'm.date'))
            ->where('m.date', $day)->where('m.door_eisen', true)
            // A make without chosen models: all its models.
            ->when($merken, fn ($q) => $q->where(function ($q) use ($merken, $modellen) {
                foreach ($merken as $merk) {
                    $q->orWhere(fn ($q) => $q->where('l.merk', $merk)
                        ->when($modellen[$merk] ?? null, fn ($q, $models) => $q->whereIn('l.model', $models)));
                }
            }))
            ->orderBy('m.rang')
            ->select(['m.*', 'd.verwachte_prijs', 'd.deal_eur', 'd.deal_pct', 'd.vergelijkbaar_n', 'd.check_dit', 'd.onbetrouwbaar'])
            ->paginate(50)->withQueryString();

        $listings = Listing::whereKey($ranking->pluck('listing_id'))->get()->keyBy('id');
        // The place within the filtered list; rang stays the place in the whole ranking.
        $plek = ($ranking->firstItem() ?? 1) - 1;
        $ranking->through(function ($row) use (&$plek, $listings, $wensen) {
            return ['plek' => ++$plek] + $this->row($row, $listings[$row->listing_id]);
        });

        return Inertia::render('VoorJou/Index', [
            'day' => $day,
            'ranking' => $ranking,
            'top' => $this->topChanges($day),
            'gewichten' => Wensen::weights($wensen),
            'filters' => $filters,
            'options' => $this->options($day),
            'bijwerken' => RecomputeMatches::pending(),
        ]);
    }

    /**
     * Makes as "BMW,Volvo" and models as "Volvo|V60,BMW|X3"; a model only counts with its make chosen.
     *
     * @return array{merk: string, model: string}
     */
    public static function filtersFrom(array $input): array
    {
        $list = fn ($value) => collect(is_array($value) ? $value : explode(',', (string) $value))
            ->map(fn ($v) => trim((string) $v))->filter()->unique()->sort()->values();

        $merken = $list($input['merk'] ?? '')->map(fn ($m) => mb_substr($m, 0, 100));
        $modellen = $list($input['model'] ?? '')
            ->filter(fn ($m) => str_contains($m, '|') && $merken->contains(explode('|', $m, 2)[0]));

        return ['merk' => $merken->implode(','), 'model' => $modellen->implode(',')];
    }

    private function remember(array $filters): void
    {
        $now = now();

        DB::table('settings')->upsert(
            [['key' => self::FILTERS_KEY, 'value' => json_encode($filters), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /**
     * The makes and models in today's ranking, with how many cars each.
     */
    private function options(string $day): array
    {
        return DB::table('match_scores as m')
            ->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->where('m.date', $day)->where('m.door_eisen', true)->whereNotNull('l.merk')
            ->groupBy('l.merk', 'l.model')->orderBy('l.merk')->orderBy('l.model')
            ->selectRaw('l.merk, l.model, count(*) as n')
            ->get()->groupBy('merk')
            ->map(fn ($rows, $merk) => [
                'merk' => $merk,
                'n' => (int) $rows->sum('n'),
                'modellen' => $rows->whereNotNull('model')->map(fn ($r) => ['model' => $r->model, 'n' => (int) $r->n])->values()->all(),
            ])->values()->all();
    }

    private function row(object $m, Listing $listing): array
    {
        $car = $listing->summary() + [
            'online_sinds' => $listing->online_sinds?->toDateString(),
            'online_sinds_bron' => $listing->online_sinds_bron,
            'garantie' => $listing->garantie,
            'aanbieder_type' => $listing->aanbieder_type,
        ];
        $deal = $m->verwachte_prijs === null && $m->vergelijkbaar_n === null ? null : (object) [
            'verwachte_prijs' => $m->verwachte_prijs,
            'deal_eur' => $m->deal_eur,
            'deal_pct' => $m->deal_pct,
            'vergelijkbaar_n' => $m->vergelijkbaar_n,
            'check_dit' => (bool) $m->check_dit,
            'onbetrouwbaar' => (bool) $m->onbetrouwbaar,
        ];

        return [
            'rang' => $m->rang,
            'cijfer' => $m->cijfer === null ? null : (float) $m->cijfer,
            'cijfers' => json_decode($m->deelscores ?? '{}', true),
            'chips' => Reasons::chips($car, $deal),
            'car' => $car,
        ];
    }

    /**
     * Today's top 10 against the day before, and since when each car is in it.
     */
    private function topChanges(string $day): array
    {
        $dates = DB::table('match_scores')->where('date', '<=', $day)->distinct()->orderByDesc('date')->limit(366)->pluck('date')->all();
        $previous = $dates[1] ?? null;

        $topOn = fn (?string $date) => $date === null ? [] : DB::table('match_scores')
            ->where('date', $date)->whereNotNull('rang')->where('rang', '<=', self::TOP)
            ->pluck('rang', 'listing_id')->all();

        $today = $topOn($day);
        $before = $topOn($previous);

        // Consecutive days in the top 10, walking back from today.
        $history = DB::table('match_scores')->whereIn('listing_id', array_keys($today))->whereIn('date', $dates)
            ->whereNotNull('rang')->where('rang', '<=', self::TOP)
            ->get(['listing_id', 'date'])->groupBy('listing_id')->map(fn ($rows) => $rows->pluck('date')->flip());

        $since = [];

        foreach (array_keys($today) as $id) {
            $first = $day;

            foreach ($dates as $date) {
                if (! isset($history[$id][$date])) {
                    break;
                }

                $first = $date;
            }

            $since[$id] = $first;
        }

        $describe = fn (array $ids, array $ranks) => Listing::whereKey($ids)->get()
            ->map(fn (Listing $l) => ['id' => $l->id, 'title' => $l->title(), 'prijs' => $l->prijs, 'rang' => $ranks[$l->id] ?? null])
            ->sortBy('rang')->values();

        $out = array_diff_key($before, $today);
        $nowRanks = DB::table('match_scores')->where('date', $day)->whereIn('listing_id', array_keys($out))->pluck('rang', 'listing_id')->all();

        return [
            'vorige_dag' => $previous,
            'sinds' => $since,
            'nieuw' => $previous ? $describe(array_keys(array_diff_key($today, $before)), $today) : [],
            // Where they are now: a lower rank, or none (sold, or no longer meeting the requirements).
            'eruit' => $describe(array_keys($out), $nowRanks)->map(fn ($c) => $c + ['vorige_rang' => $before[$c['id']]])->values(),
        ];
    }
}
