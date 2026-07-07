<?php

/*
|--------------------------------------------------------------------------
| SEO-CMS — paginaregister
|--------------------------------------------------------------------------
| De hoofd-marketingpagina's van milmap.nl waarvoor in de admin per taal SEO
| kan worden ingesteld. `key` is de stabiele identifier (ook in seo_overrides),
| `path` is het (NL/default) pad dat milmap.nl gebruikt om de override op te
| zoeken. Voeg hier een regel toe om een pagina aan het CMS toe te voegen.
*/

return [

    'locales' => ['nl', 'en', 'de', 'es'],

    'pages' => [
        ['key' => 'home',         'path' => '/',             'label' => 'Home'],
        ['key' => 'features',     'path' => '/features',     'label' => 'Features (overzicht)'],
        ['key' => 'oplossingen',  'path' => '/oplossingen',  'label' => 'Oplossingen (overzicht)'],
        ['key' => 'pricing',      'path' => '/pricing',      'label' => 'Prijzen'],
        ['key' => 'blog',         'path' => '/blog',         'label' => 'Blog (overzicht)'],
        ['key' => 'marketplace',  'path' => '/marketplace',  'label' => 'Marketplace (overzicht)'],
        ['key' => 'app',          'path' => '/app',          'label' => 'App-download'],
        ['key' => 'legal',        'path' => '/legal',        'label' => 'Juridisch (overzicht)'],
    ],

];
