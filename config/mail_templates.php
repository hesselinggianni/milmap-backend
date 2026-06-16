<?php

/**
 * Registry van campagne-mailtemplates die de admin kan kiezen voor een campagne.
 * Deze templates worden in de backend geschreven (resources/views/emails/campaigns/
 * {key}/{locale}.blade.php) en hier geregistreerd met hun metadata.
 *
 * - locales : in welke talen de template bestaat (NL is de fallback)
 * - subjects: onderwerp per taal
 * - view    : basis-viewnaam; de resolver plakt er ".{locale}" achter
 *             (App\Services\MailTemplateRegistry::resolve)
 * - category: voorgestelde standaard-categorie-key (de campagne kiest de echte)
 *
 * Let op: campagne-templates mogen ALLEEN generieke variabelen verwachten die voor
 * elke ontvanger bekend zijn (name, appUrl, siteUrl, unsubscribeUrl, pixelUrl) —
 * géén per-gebruiker data zoals een resettoken of coupon, want ze gaan in bulk uit.
 */
return [
    'become_customer' => [
        'label'       => 'Klant worden bij MilMap',
        'description' => 'Nodig leads en gratis gebruikers uit om een betaald abonnement te nemen.',
        'category'    => 'offers',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Word vandaag nog lid van MilMap',
            'en' => 'Become a MilMap member today',
        ],
        'view'        => 'emails.campaigns.become_customer',
    ],

    'tips' => [
        'label'       => 'Tips & tricks over MilMap',
        'description' => 'Praktische tips en uitleg over functies van MilMap.',
        'category'    => 'tips',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'MilMap-tip: haal meer uit je kaarten',
            'en' => 'MilMap tip: get more out of your maps',
        ],
        'view'        => 'emails.campaigns.tips',
    ],
];
