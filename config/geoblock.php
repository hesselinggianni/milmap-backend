<?php

return [
    // ISO-2 landcodes waarvan het gebruik van MilMap is uitgesloten
    // (Iran, Irak, Rusland, China, Noord-Korea, Afghanistan).
    // Overschrijfbaar via GEOBLOCK_COUNTRIES="IR,IQ,..." ; leeg = uitgeschakeld.
    'countries' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GEOBLOCK_COUNTRIES', 'IR,IQ,RU,CN,KP,AF'))
    ))),
];
