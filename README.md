# auto.munne.me — occasion-prijstracker

Fetches every page of the active Gaspedaal searches once a day and stores every car, with its price and km for each day. The assignment is in `BOUWOPDRACHT.md`.

## Deploying

Every push to `main` deploys itself: `.github/workflows/deploy.yml` runs on a GitHub Actions runner on the server (`~/actions-runner-autotracker`, labels `self-hosted, autotracker`), brings `/srv/auto` to the pushed commit and runs `deploy/deploy.sh`. That script runs the tests, builds `auto:<commit>`, waits until the queue worker runs no job (`auto:idle`, at most an hour), dumps the database and migrates when migrations are pending, then makes it `auto:latest` and recreates the containers. When `/up` does not become healthy it puts the previous image back. Run it again from the Actions tab ("Run workflow"), or by hand: `deploy/deploy.sh`.

The first setup, by hand:

```sh
cd /srv/auto
# once: docker volume create auto-storage && docker volume create auto-mysql   (already done)
docker compose --env-file .env -f deploy/compose.prod.yml up -d --build
docker compose --env-file .env -f deploy/compose.prod.yml exec app php artisan migrate --force
docker compose --env-file .env -f deploy/compose.prod.yml exec app php artisan db:seed --force   # the 6 start sources, once
docker compose --env-file .env -f deploy/compose.prod.yml exec app php artisan auto:user lars    # asks for a password; again to change it
```

The login is the app's own (name + password, "Ingelogd blijven" keeps you in). Everything but `/login` and `/up` requires it; car photos under `/storage/photos` are public files.

## How it works

- **Daily run**: the scheduler runs `auto:scrape --jitter` at 05:15 plus a random 0–75 min. Each source becomes a queue job. The one worker runs them one after another, with 3–7 s between pages and 20–60 s between sources. A failed page is retried twice (after 15 s and 60 s).
- **Paging**: `&page=N`, 100 per page. `numberOfPages` and `pageNumber` from the page data are checked.
- **Complete or not**: a run is `success` only when every page came in and at least 97% of `totalOccasions` was seen. Only then are cars it didn't see marked gone (`listing_source.gone_at`, and `listings.gone_at` once no source has the car). A run that is `incomplete` or `failed` keeps what it saw but marks nothing gone.
- **Storage**: every raw page is stored gzipped under `storage/app/private/raw/<date>/source-<id>/` and listed in `scrape_pages`. The raw occasion JSON goes in `listing_snapshots`, stored again only when it changed. `observations` holds one row per car per day (price, km, snapshot_id), so "seen on day X" is simply that row.
- **Monitoring**: the red banner on every page; `auto:health` every hour. Set `MONITOR_PUSH_URL` to an Uptime Kuma *push* monitor (heartbeat interval ~3600 s plus some slack). It then gets `up` or `down` with the reason, and Kuma alerts when the status is down or the pushes stop.
- **Backup**: Backrest backs up the server. A deploy with pending migrations first dumps the database to `~/backups/auto-predeploy` (the last 10 are kept).

## Seller type, warranty, btw

The occasion JSON has no seller type. Gaspedaal does have URL filters: `vrk` (PARTICULIER / UNIVERSEEL_AUTOBEDRIJF), `grn` (MERKGARANTIE / BOVAG) and `btw` (true / false). Each run makes extra passes of its source with `vrk=PARTICULIER`, `grn=MERKGARANTIE`, `grn=BOVAG` and `btw=true` (see `Scraper::FACETS`). The results go to `listings.particulier`, `garantie`, `btw_verrekenbaar` and `kenmerken_op`. A pass only counts when its total matches the count in Gaspedaal's own filter sidebar on page 1 of the search; that catches a filter Gaspedaal ignores. Passes for 0 cars are skipped. A source whose URL already filters on one of these keys skips that pass. Pages of a pass are in `scrape_pages` with `facet` set.

`aanbieder_type` (`App\Gaspedaal\SellerType`):
- **particulier**: `vrk=PARTICULIER`, or, before that ran, name and province `"$undefined"` (`zonder_aanbieder`).
- **merkdealer**: merkgarantie, or the importer's portal of the car's own brand (`importeur_portaal`; Volvo has one, BMW and VW don't). It also carries over to other cars of the same brand from the same dealer (`dealer_slug`, from the ld+json seller URL, only present for ads through Dealersite).
- **handelaar**: the rest.

## Uitrusting: comfort level and sport

`App\Uitrusting\Classifier` with the table and keywords in `config/uitrusting.php`. Text and keywords are normalised the same way (lower case, punctuation and hyphens become spaces, "+" after a word becomes " plus") and compared as whole words. Lines that fall inside a more specific matching line drop out; of the lines left in the title, the highest level wins. Each car stores `comfort_niveau`, `sport`, `uitvoeringslijn`, `uitrusting_opties` and `uitrusting_versie` (a hash of the config). A run classifies a car again when its title changed or the config did; `php artisan auto:uitrusting` does all cars at once. `/uitvoeringen` lists the titles with no recognised line.

## Recommendations: deal score, rapportcijfer, "Voor jou"

`php artisan auto:scores` (also queued right behind the daily run, with a 10:00 fallback) computes for the cars online now (`App\Recommend\Scores`):
- **online_sinds**: `datum_geplaatst` unless it is far from the dates of ads with neighbouring ids, or later than `first_seen`; then `first_seen` (`App\Deals\OnlineSince`).
- **model_factors**: OLS `prijs = a + b·months + c·km` per model (≥15 cars), else per brand, else all. Factors where price rises with age or km are refused. Age is counted from 1 July of the build year: the data has no first registration date.
- **deal_scores** (per car per day): comparable cars (same model, year ±1, km ±15k, pk ±15%, comfort ±1, widened in `Comparables::STEPS` when fewer than 5), corrected to the car's age and km. The expected price is the median. More than 15% below it is "Check dit"; fewer than 3 comparable cars gives no score.
- **match_scores** (per car per day): the rapportcijfer of every car online (`cijfer`, 1,0–10,0; grade per vak in `deelscores`), from the vakken in `settings.wensen` (`App\Recommend\Wensen`, page /wensen). Saving the vakken recomputes today's cijfers on the `scores` queue. Vakken deal, km, bouwjaar, luxe and vermogen (`App\Recommend\MatchScorer`) each grade on a straight line through the value that earns a 6 (`zes`) and the one that earns a 10 (`tien`), kept within 1–10: deal 6 at the market price and 10 at `tien`% below it; luxe comfort level 3/2/1 is 10/7/4. An unknown value, "Check dit", too few comparable cars or an unrecognised trim line gives no grade, and that vak does not count. The cijfer is the average weighted by each vak's importance (0/1/3/5).
- **"Voor jou"** (`App\Recommend\Ranking`): the cars of the latest day with cijfers, through the filters above the list, by cijfer and then the lowest price; the place (`rang`) is within the filters. The filters are applied on every request, so changing them needs no new calculation; they are kept in `settings.wensen` (`filters`). Make and model (a make without chosen models: all its models), price, bouwjaar and km from–to, comfort levels, sport, pk from–to, colours (a car without a colour passes unless `onbekende_kleur` is off) and only brand dealer. An empty list or an unset bound lets every car through; a set filter lets no car through whose value is unknown.

## Analysis page (/analyse)

- Filters combine and live in the URL (bookmarkable). Colour takes several at once (`?kleur=GRIJS,ZWART`). "Now" figures use the cars online now; the price per day uses `observations`: the cars seen that day that matched, with price and km filtered on that day's values and the car's properties on the listing.
- Saved filters (`saved_filters`) keep a fixed colour slot (1–8, the dataviz reference palette), so a filter has the same colour in every comparison. `?vergelijk=1,3` shows those in one chart.

## Handy

```sh
c="docker compose --env-file .env -f deploy/compose.prod.yml"
$c exec app php artisan auto:scrape 3 --sync   # run source 3 now, here
$c exec app php artisan auto:health
$c logs -f queue
```

Tests: `docker run --rm -u 1000:1000 -v /srv/auto:/w -w /w composer:2 php artisan test`
