<?php

namespace App\Http\Controllers;

use App\Jobs\RecomputeMatches;
use App\Models\Listing;
use App\Recommend\Ranking;
use App\Recommend\Scores;
use App\Recommend\Wensen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mijn wensen": the vakken of the rapportcijfer. The filters are on "Voor jou" itself.
 */
class WensenController extends Controller
{
    /** How many cars the live preview shows. */
    public const int TOP = 5;

    public function edit(): Response
    {
        return Inertia::render('Wensen/Edit', [
            'vakken' => Wensen::get()['vakken'],
            'standaard' => Wensen::defaults()['vakken'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Wensen::save(['vakken' => $this->vakken($request)] + Wensen::get());

        RecomputeMatches::start();

        return redirect()->route('voor-jou')->with('flash', 'Wensen opgeslagen; de cijfers worden bijgewerkt.');
    }

    /**
     * The top 5 these (unsaved) vakken would give through the saved filters, and how each car moved
     * against the list "Voor jou" shows now.
     */
    public function preview(Request $request, Scores $scores): JsonResponse
    {
        $wensen = ['vakken' => $this->vakken($request)] + Wensen::get();
        $result = $scores->preview($wensen);
        $top = array_slice($result['ids'], 0, self::TOP);

        $day = Ranking::day();
        $saved = $day ? array_flip(Ranking::rank(Ranking::cars($day), Wensen::get())) : [];
        $listings = Listing::whereKey($top)->get()->keyBy('id');

        return response()->json([
            'voldoen' => count($result['ids']),
            'top' => collect($top)->map(function ($id, $i) use ($listings, $saved, $result) {
                $was = isset($saved[$id]) ? $saved[$id] + 1 : null;

                return [
                    'id' => $id,
                    'rang' => $i + 1,
                    'cijfer' => $result['cijfers'][$id],
                    // Places up (positive) or down against the list now; null: not in it yet.
                    'verschuiving' => $was === null ? null : $was - ($i + 1),
                    'car' => array_intersect_key($listings[$id]->summary(), array_flip(['title', 'bouwjaar', 'kilometerstand', 'prijs', 'foto'])),
                ];
            })->values(),
        ]);
    }

    private function vakken(Request $request): array
    {
        $data = $request->validate([
            'vakken' => ['required', 'array'],
            'vakken.*.belang' => ['required', 'integer', Rule::in(Wensen::BELANG)],
            // What earns a 10 lies beyond the 6, on the good side.
            'vakken.deal.tien' => ['required', 'numeric', 'gt:0', 'max:50'],
            'vakken.km.zes' => ['required', 'integer', 'min:1'],
            'vakken.km.tien' => ['required', 'integer', 'min:0', 'lt:vakken.km.zes'],
            'vakken.bouwjaar.zes' => ['required', 'integer', 'between:1900,2100'],
            'vakken.bouwjaar.tien' => ['required', 'integer', 'gt:vakken.bouwjaar.zes', 'max:2100'],
            'vakken.vermogen.zes' => ['required', 'integer', 'min:1'],
            'vakken.vermogen.tien' => ['required', 'integer', 'gt:vakken.vermogen.zes'],
        ], [
            'vakken.km.tien.lt' => 'Het aantal km voor een 10 moet onder dat voor een 6 liggen.',
            'vakken.bouwjaar.tien.gt' => 'Het bouwjaar voor een 10 moet na dat voor een 6 liggen.',
            'vakken.vermogen.tien.gt' => 'Het vermogen voor een 10 moet boven dat voor een 6 liggen.',
        ]);

        $vakken = array_intersect_key(array_replace_recursive(Wensen::defaults()['vakken'], $data['vakken']), array_flip(Wensen::VAKKEN));
        $vakken['deal']['tien'] = (float) $vakken['deal']['tien'];

        return $vakken;
    }
}
