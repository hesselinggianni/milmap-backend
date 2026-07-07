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

    // ── Onboarding-funnel (Project 8) ───────────────────────────────────────
    // Zes stappen die na registratie automatisch worden verzonden (dag 0/1/3/5/
    // 7/14) via de auto-enroll-campagne + follow-up-engine. Alleen generieke
    // variabelen (name, appUrl, siteUrl, unsubscribeUrl, pixelUrl).
    'onboarding_welcome' => [
        'label'       => 'Onboarding — Dag 0: Welkom',
        'description' => 'Welkomstmail direct na registratie (start van de funnel).',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Welkom bij MilMap — je 7 dagen zijn gestart',
            'en' => 'Welcome to MilMap — your 7 days have started',
        ],
        'view'        => 'emails.campaigns.onboarding_welcome',
    ],

    'onboarding_first_map' => [
        'label'       => 'Onboarding — Dag 1: Eerste kaart maken',
        'description' => 'Zet de gebruiker aan het werk met een eerste kaart.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Maak vandaag je eerste kaart in MilMap',
            'en' => 'Create your first map in MilMap today',
        ],
        'view'        => 'emails.campaigns.onboarding_first_map',
    ],

    'onboarding_advanced' => [
        'label'       => 'Onboarding — Dag 3: Geavanceerde functies',
        'description' => 'Laat missies, 9-liner, terreinanalyse en offline kaarten zien.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Ontdek de kracht van MilMap: missies, terrein & meer',
            'en' => 'Discover MilMap\'s power: missions, terrain & more',
        ],
        'view'        => 'emails.campaigns.onboarding_advanced',
    ],

    'onboarding_case' => [
        'label'       => 'Onboarding — Dag 5: Klantcase',
        'description' => 'Sociaal bewijs: hoe eenheden MilMap in het veld gebruiken.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Zo gebruiken eenheden MilMap in het veld',
            'en' => 'How units use MilMap in the field',
        ],
        'view'        => 'emails.campaigns.onboarding_case',
    ],

    'onboarding_upgrade' => [
        'label'       => 'Onboarding — Dag 7: Upgrade naar Premium',
        'description' => 'Proef loopt af — zet aan tot een abonnement.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Je proefperiode loopt af — behoud alle functies',
            'en' => 'Your trial is ending — keep all features',
        ],
        'view'        => 'emails.campaigns.onboarding_upgrade',
    ],

    'onboarding_discount' => [
        'label'       => 'Onboarding — Dag 14: Tijdelijke korting',
        'description' => 'Laatste zetje met een tijdelijke actie.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en'],
        'subjects'    => [
            'nl' => 'Tijdelijk: stap nu over op MilMap Premium',
            'en' => 'Limited time: upgrade to MilMap Premium now',
        ],
        'view'        => 'emails.campaigns.onboarding_discount',
    ],
];
