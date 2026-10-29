<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Periodieke admin-overzichtsmails
    |--------------------------------------------------------------------------
    | Dag-, week-, maand-, kwartaal- en jaaroverzicht van aanmeldingen, leads en
    | de totaalstand (zie App\Services\AdminDigestService, het commando
    | `digest:send` en routes/console.php voor het schema).
    |
    | ADMIN_DIGEST_TO mag meerdere adressen bevatten, kommagescheiden. Standaard
    | dezelfde ontvanger als de realtime meldingen "nieuwe gebruiker"/"nieuwe lead".
    */
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_DIGEST_TO', 'hesselinggianni@gmail.com'))
    ))),

    // Taal van de mail (lang/{nl,en}/mail.php → admin_digest).
    'locale' => env('ADMIN_DIGEST_LOCALE', 'nl'),

];
