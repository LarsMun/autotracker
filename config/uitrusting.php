<?php

/*
 * Uitrustingsclassificatie (App\Uitrusting\Classifier): comfortniveau 1–3 en sport ja/nee per auto,
 * uit modelnaam + uitvoering.
 *
 * Matching: tekst en trefwoorden worden op dezelfde manier genormaliseerd (kleine letters,
 * leestekens en streepjes worden spaties, "+" achter een woord wordt " plus", accenten weg), en
 * daarna op hele woorden vergeleken. "S-Line", "S line" en "s-line" zijn dus hetzelfde.
 *
 * Na een wijziging hier: php artisan auto:uitrusting (de runs doen het ook vanzelf, per auto die
 * ze zien, omdat de versie van dit bestand bij elke auto wordt bewaard).
 */

return [

    // Merknaam zoals Gaspedaal hem geeft (genormaliseerd) => sleutel in de tabellen hieronder.
    'merken' => [
        'volvo' => 'volvo',
        'bmw' => 'bmw',
        'audi' => 'audi',
        'volkswagen' => 'volkswagen',
        'vw' => 'volkswagen',
        'mercedes benz' => 'mercedes',
        'mercedes' => 'mercedes',
        'skoda' => 'skoda',
    ],

    /*
     * Stap 1: uitvoeringslijnen per merk => basisniveau. De specifiekste lijn wint (langste
     * trefwoorden eerst): "Business Edition" telt niet als "Business Edition Plus" er staat. Staan er
     * los van elkaar meerdere lijnen in de titel (bijv. "M40i ... High Executive"), dan de hoogste.
     *
     * - "A + B": beide moeten voorkomen (bijv. High Executive in combinatie met M Sport).
     * - ['niveau' => 1, 'niet' => [...]]: geldt niet als een van die woorden ook voorkomt.
     */
    'lijnen' => [
        'volvo' => [
            'Core' => 1,
            'Essential' => 1,
            'Momentum' => 1,
            'Base' => 1,
            'Plus' => 2,
            'Inscription' => 2,
            'Ultimate' => 3,
            'Polestar Engineered' => 3,
        ],
        'bmw' => [
            'Business Edition' => 1,
            'Executive' => 1,
            'Business Edition Plus' => 2,
            'High Executive' => 2,
            'High Executive + M Sport' => 3,
            'High Executive + M Sportpakket' => 3,
            'M40i' => 3,
            'M340i' => 3,
            'M50' => 3,
            'M50i' => 3,
            'M550i' => 3,
        ],
        'audi' => [
            'Advanced edition' => 1,
            'Pro Line' => 1,
            'S edition' => 2,
            'Business edition' => 2,
            'Launch edition' => 2,
            'S line Competition' => 3,
            'S edition Competition' => 3,
            'Pro Line S' => 3,
            'S4' => 3,
            'S6' => 3,
        ],
        'volkswagen' => [
            'Business' => ['niveau' => 1, 'niet' => ['R-Line']],
            'Elegance' => ['niveau' => 1, 'niet' => ['R-Line']],
            'R-Line' => 2,
            'R-Line Business' => 2,
            'R-Line Business+' => 2,
            // Oudere naam voor R-Line Business (Passat, Arteon); zonder dit telt de losse "R" als R-model.
            'Business R' => 2,
            'R' => 3,
        ],
        'mercedes' => [
            'Business Solution' => 1,
            'Avantgarde' => 1,
            'AMG Line' => 2,
            'AMG Line Premium' => 3,
            'AMG Line Premium Plus' => 3,
            // Zo schrijven de meeste advertenties AMG Line Premium (Plus).
            'AMG Premium' => 3,
            'AMG Premium Plus' => 3,
            // Business Solution met het AMG Line-pakket, zo genoemd door Mercedes.
            'Business Solution AMG' => 2,
        ],
        'skoda' => [
            'Business Edition' => 1,
            'Style' => 1,
            'Ambition' => 1,
            'Sportline' => 2,
            'Business Edition Plus' => 2,
            'L&K' => 3,
            'Laurin & Klement' => 3,
        ],
    ],

    // Stap 2: optiepunten; elke optie telt hooguit één keer.
    'opties' => [
        'pano' => ['panorama', 'panoramadak', 'pano', 'schuifdak', 'schuif-kanteldak'],
        'leer' => ['leer', 'leder', 'lederen', 'vol leder', 'nappa'],
        'hud' => ['head-up', 'head up', 'hud'],
        'camera360' => ['360', '360°', 'surround view', 'area view', '360 camera'],
        'acc' => ['acc', 'adaptive cruise', 'adaptieve cruise', 'adaptief cruise'],
        'audio' => ['harman', 'h&k', 'harman kardon', 'bowers', 'b&w', 'bang & olufsen', 'b&o', 'burmester'],
        'comfortstoelen' => ['massage', 'memory', 'stoelventilatie', 'ventilatie', 'comfortstoelen'],
        'trekhaak' => ['trekhaak', 'ahk', 'wegklapbare trekhaak'],
        'matrix' => ['matrix', 'laser', 'iq.light', 'iqlight'],
    ],

    // Lijn herkend: zoveel optiepunten of meer geeft een niveau extra (max 3).
    'opties_voor_extra_niveau' => 3,

    // Geen lijn herkend: niveau uit het aantal optiepunten (minimaal aantal => niveau).
    'niveau_uit_opties' => [0 => 1, 2 => 2, 3 => 3],

    // Sport = ja als een van deze voorkomt; '*' geldt voor elk merk.
    'sport' => [
        '*' => ['M Sport', 'M-Sport', 'M Sportpakket', 'S line', 'S-line', 'R-Line', 'AMG Line', 'R-Design', 'Sportline', 'Black Edition'],
        'volvo' => ['Dark', 'Polestar'],
        'bmw' => ['M40i', 'M340i'],
        'audi' => ['S4', 'S6'],
        'volkswagen' => ['R'],
        // AMG Line Premium (Plus) heeft het AMG Line-pakket; advertenties schrijven vaak "AMG Premium".
        'mercedes' => ['AMG Premium', 'Business Solution AMG'],
    ],
];
