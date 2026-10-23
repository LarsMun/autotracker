<?php

namespace App\Http\Controllers;

use App\Jobs\RecomputeMatches;
use App\Models\Listing;
use App\Recommend\Ranking;
use App\Recommend\Reasons;
use App\Recommend\Wensen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class VoorJouController extends Controller
{
    public const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $day = Ranking::day();
        $wensen = Wensen::get();
        $cars = $day ? Ranking::cars($day) : [];
        $ids = Ranking::rank($cars, $wensen);

        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageIds = array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $listings = Listing::whereKey($pageIds)->get()->keyBy('id');
        $first = ($page - 1) * self::PER_PAGE;

        $rows = array_map(fn ($id, $i) => $this->row($first + $i + 1, $cars[$id], $listings[$id]), $pageIds, array_keys($pageIds));
        $ranking = (new LengthAwarePaginator($rows, count($ids), self::PER_PAGE, $page))->withPath($request->url());

        return Inertia::render('VoorJou/Index', [
            'day' => $day,
            'ranking' => $ranking,
            'gewichten' => Wensen::weights($wensen),
            'filters' => $wensen['filters'],
            'standaard' => Wensen::defaults()['filters'],
            'options' => Ranking::options($cars),
            'bijwerken' => RecomputeMatches::pending(),
        ]);
    }

    /**
     * New filters: stored with the wensen, the list follows at once.
     */
    public function filters(Request $request): RedirectResponse
    {
        Wensen::saveFilters(self::filtersFrom($request));

        return redirect()->route('voor-jou');
    }

    public static function filtersFrom(Request $request): array
    {
        $data = $request->validate([
            'merken' => ['array'],
            'merken.*' => ['string', 'max:100'],
            'modellen' => ['array'],
            'modellen.*' => ['string', 'max:200'],
            'prijs_min' => ['nullable', 'integer', 'min:0'],
            'prijs_max' => ['nullable', 'integer', 'min:0'],
            'bouwjaar_min' => ['nullable', 'integer', 'between:1900,2100'],
            'bouwjaar_max' => ['nullable', 'integer', 'between:1900,2100'],
            'km_min' => ['nullable', 'integer', 'min:0'],
            'km_max' => ['nullable', 'integer', 'min:0'],
            'luxe' => ['array'],
            'luxe.*' => ['integer', 'between:1,3'],
            'sport' => ['boolean'],
            'pk_min' => ['nullable', 'integer', 'min:0'],
            'pk_max' => ['nullable', 'integer', 'min:0'],
            'kleuren' => ['array'],
            'kleuren.*' => ['string', 'max:50'],
            'onbekende_kleur' => ['boolean'],
            'alleen_merkdealer' => ['boolean'],
        ]);

        $list = fn (string $key, bool $int = false) => collect($data[$key] ?? [])->map(fn ($v) => $int ? (int) $v : (string) $v)->unique()->sort()->values()->all();
        $bound = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;
        $merken = $list('merken');

        return [
            'merken' => $merken,
            // A model counts only with its make chosen.
            'modellen' => array_values(array_filter($list('modellen'), fn ($m) => str_contains($m, '|') && in_array(explode('|', $m, 2)[0], $merken, true))),
            'prijs_min' => $bound('prijs_min'),
            'prijs_max' => $bound('prijs_max'),
            'bouwjaar_min' => $bound('bouwjaar_min'),
            'bouwjaar_max' => $bound('bouwjaar_max'),
            'km_min' => $bound('km_min'),
            'km_max' => $bound('km_max'),
            'luxe' => $list('luxe', true),
            'sport' => (bool) ($data['sport'] ?? false),
            'pk_min' => $bound('pk_min'),
            'pk_max' => $bound('pk_max'),
            'kleuren' => $list('kleuren'),
            'onbekende_kleur' => (bool) ($data['onbekende_kleur'] ?? true),
            'alleen_merkdealer' => (bool) ($data['alleen_merkdealer'] ?? false),
        ];
    }

    private function row(int $rang, array $car, Listing $listing): array
    {
        $summary = $listing->summary() + [
            'online_sinds' => $listing->online_sinds?->toDateString(),
            'online_sinds_bron' => $listing->online_sinds_bron,
        ];

        return [
            'rang' => $rang,
            'cijfer' => $car['cijfer'],
            'cijfers' => $car['cijfers'],
            'chips' => Reasons::chips($summary, $car['deal']),
            'car' => $summary,
        ];
    }
}
