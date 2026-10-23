# Bouwopdracht: auto.munne.me – occasion-prijstracker

## Doel
Dagelijks occasions van Gaspedaal.nl verzamelen voor door mij gekozen merk/model-zoekopdrachten, om tot ca. maart het prijsverloop te kunnen analyseren. **Fase 1 = zo veel mogelijk data betrouwbaar verzamelen.** Analyse/dashboard komt later; ik bepaal zelf hoe dat eruitziet.

## Omgeving
- Ubuntu-minicomputer, Docker-stack achter Traefik. Bereikbaar via `auto.munne.me` (Traefik-labels/router zoals mijn bestaande services; bekijk mijn huidige stack/compose voor de conventies).
- Alleen voor eigen gebruik: zet de site achter authenticatie (bijv. Traefik basic-auth middleware of Laravel-login).
- Stack: Laravel + Vue (Inertia), database MySQL of Postgres als container. Scheduler/queue als aparte container of cron in de app-container.

## Functies fase 1
1. **Bronnen beheren**: ik voer Gaspedaal-zoek-URL's in (bijv. `https://www.gaspedaal.nl/bmw/3-serie/stationwagen?bmin=2020`) met een naam. Aan/uit-zetten, verwijderen.
2. **Preview bij toevoegen**: na invoeren direct pagina 1 ophalen en tonen: totaal aantal resultaten + lijst auto's met foto, titel/uitvoering, bouwjaar, km, prijs, pk, plaats.
3. **Dagelijkse run**: alle actieve bronnen ophalen, alle pagina's, opslaan. Rustig: pauze van een paar seconden tussen verzoeken, tijdstip licht variëren.
4. **Overzicht**: per bron aantal auto's, nieuw/verdwenen sinds gisteren, laatste run-status. Per auto een detailpagina met prijs- en km-historie en foto.
5. **Bewaking**: run mislukt of levert 0 auto's op → duidelijk zichtbaar (en liefst een melding).

## Hoe Gaspedaal de data levert (al onderzocht)
- Gewone HTTP-GET volstaat; **geen headless browser nodig**. Gebruik een normale browser-User-Agent en `Accept-Language: nl-NL`.
- Next.js App Router: data staat in `<script>self.__next_f.push([1,"..."])</script>`-blokken. Elk array-argument is geldige JSON; voeg de string-delen samen tot één tekst.
- Daarin zit `"occasions":[...]` (uitknippen met string-bewuste haakjesteller, dan `JSON.parse`) en `"commonPortalURLProps":{"sort":..,"totalOccasions":..,"pageSize":100,"pageNumber":1}` plus `"numberOfPages":N`.
- Per occasion o.a.:
  - `advertentieId` (stabiel ID → unieke sleutel)
  - `datumGeplaatst` (online sinds)
  - `aanbieder.aanbiedergegevens`: `naamsvermelding`, `plaatsnaam`, `provincieHulpwaarde`
  - `autogegevens.algemeen`: `merknaam`, `modelnaam`, `uitvoering`, `brandstofsoort`, `transmissietype`, `carrosserievorm`, `kleur`, `aantalDeuren`
  - `autogegevens.geschiedenis`: `bouwjaar`, `kilometerstand`
  - `autogegevens.motor`: `motorinhoud`, `vermogenKw` (tonen als pk = kW × 1,36)
  - `prijs.totaalInclusiefBtw`
  - `gpFotos.fotoKlein` / `fotoGroot`
  - `portalen[]` met `klikUrl` per bronsite (structuur nog verifiëren)
- Fallback/controle: `<script type="application/ld+json">` met een schema.org `ItemList` (alleen de huidige pagina).
- **Paginering nog uitzoeken**: test welke parameter pagina 2 geeft (`p`, `page`, `pagina`, …) op een zoekopdracht met >100 resultaten, en verifieer via `pageNumber` en andere `advertentieId`'s.
- Bewaar alle velden; bewaar de volledige ruwe occasion-JSON per auto per dag ook, voor velden die we nu nog niet gebruiken.

## Datamodel (minimaal)
- `sources`: naam, url, actief, laatste run.
- `listings`: één rij per `advertentieId` met vaste kenmerken, `first_seen`, `last_seen`, `datum_geplaatst`.
- `listing_source`: koppeling auto ↔ bron (een auto kan in meerdere bronnen voorkomen; nooit dubbel tellen).
- `observations`: per auto per dag prijs, km, gezien-op (alleen nieuwe rij hoeft niet als er niets wijzigt, maar "gezien op dag X" moet afleidbaar zijn).
- `scrape_runs` + ruwe pagina's (gecomprimeerd op schijf of in DB) zodat alles later opnieuw te parsen is.
- Een auto die een dag niet meer voorkomt in een volledige, geslaagde run = verdwenen (`last_seen`). Bij een mislukte run niets als verdwenen markeren.

## Overig
- Dagelijkse databaseback-up naar een plek buiten de container.
- Begin met deze bronnen (URL's in de browser verifiëren):
  - https://www.gaspedaal.nl/bmw/3-serie/stationwagen?bmin=2020
  - https://www.gaspedaal.nl/bmw/5-serie/stationwagen?bmin=2020
  - https://www.gaspedaal.nl/bmw/x3?bmin=2020
  - https://www.gaspedaal.nl/volvo/xc60?bmin=2020
  - https://www.gaspedaal.nl/volvo/v90?bmin=2020
  - https://www.gaspedaal.nl/volvo/v60?bmin=2020
