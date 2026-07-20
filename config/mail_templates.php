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
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Welkom bij MilMap — je 7 dagen zijn gestart',
            'en' => 'Welcome to MilMap — your 7 days have started',
            'de' => 'Willkommen bei MilMap — deine 7 Tage haben begonnen',
            'es' => 'Bienvenido a MilMap — tus 7 días han comenzado',
            'fr' => 'Bienvenue sur MilMap — vos 7 jours ont commencé',
            'uk' => 'Ласкаво просимо до MilMap — ваші 7 днів розпочалися',
            'pl' => 'Witamy w MilMap — Twoje 7 dni się rozpoczęło',
            'it' => 'Benvenuto su MilMap — i tuoi 7 giorni sono iniziati',
            'pt' => 'Bem-vindo ao MilMap — os teus 7 dias começaram',
            'lt' => 'Sveiki atvykę į MilMap — jūsų 7 dienos prasidėjo',
            'ja' => 'MilMapへようこそ — 7日間のトライアルが始まりました',
            'tr' => 'MilMap\'e Hoş Geldiniz — 7 günün başladı',
        ],
        'view'        => 'emails.campaigns.onboarding_welcome',
    ],

    'onboarding_first_map' => [
        'label'       => 'Onboarding — Dag 1: Eerste kaart maken',
        'description' => 'Zet de gebruiker aan het werk met een eerste kaart.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Maak vandaag je eerste kaart in MilMap',
            'en' => 'Create your first map in MilMap today',
            'de' => 'Erstelle noch heute deine erste Karte in MilMap',
            'es' => 'Crea hoy tu primer mapa en MilMap',
            'fr' => 'Créez aujourd\'hui votre première carte dans MilMap',
            'uk' => 'Створіть сьогодні свою першу карту в MilMap',
            'pl' => 'Utwórz dziś swoją pierwszą mapę w MilMap',
            'it' => 'Crea oggi la tua prima mappa su MilMap',
            'pt' => 'Cria hoje o teu primeiro mapa no MilMap',
            'lt' => 'Sukurkite savo pirmąjį žemėlapį MilMap jau šiandien',
            'ja' => '今日、MilMapで最初の地図を作成しましょう',
            'tr' => 'Bugün MilMap\'te ilk haritanı oluştur',
        ],
        'view'        => 'emails.campaigns.onboarding_first_map',
    ],

    'onboarding_advanced' => [
        'label'       => 'Onboarding — Dag 3: Geavanceerde functies',
        'description' => 'Laat missies, 9-liner, terreinanalyse en offline kaarten zien.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Ontdek de kracht van MilMap: missies, terrein & meer',
            'en' => 'Discover MilMap\'s power: missions, terrain & more',
            'de' => 'Entdecke die Stärken von MilMap: Missionen, Gelände & mehr',
            'es' => 'Descubre el poder de MilMap: misiones, terreno y más',
            'fr' => 'Découvrez la puissance de MilMap : missions, terrain et plus encore',
            'uk' => 'Відкрийте силу MilMap: місії, місцевість та більше',
            'pl' => 'Odkryj siłę MilMap: misje, teren i więcej',
            'it' => 'Scopri la potenza di MilMap: missioni, terreno e altro',
            'pt' => 'Descobre o poder do MilMap: missões, terreno & muito mais',
            'lt' => 'Atraskite MilMap galią: misijos, vietovė ir daugiau',
            'ja' => 'MilMapの真価を発見：ミッション、地形分析など',
            'tr' => 'MilMap\'in gücünü keşfet: görevler, arazi ve daha fazlası',
        ],
        'view'        => 'emails.campaigns.onboarding_advanced',
    ],

    'onboarding_case' => [
        'label'       => 'Onboarding — Dag 5: Klantcase',
        'description' => 'Sociaal bewijs: hoe eenheden MilMap in het veld gebruiken.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Zo gebruiken eenheden MilMap in het veld',
            'en' => 'How units use MilMap in the field',
            'de' => 'So nutzen Einheiten MilMap im Einsatz',
            'es' => 'Así usan MilMap las unidades sobre el terreno',
            'fr' => 'Comment les unités utilisent MilMap sur le terrain',
            'uk' => 'Ось як підрозділи використовують MilMap у полі',
            'pl' => 'Jak jednostki korzystają z MilMap w terenie',
            'it' => 'Ecco come le unità usano MilMap sul campo',
            'pt' => 'Como as unidades usam o MilMap no terreno',
            'lt' => 'Taip padaliniai naudoja MilMap lauko sąlygomis',
            'ja' => '部隊はMilMapを現場でこう活用しています',
            'tr' => 'Birlikler MilMap\'i sahada böyle kullanıyor',
        ],
        'view'        => 'emails.campaigns.onboarding_case',
    ],

    'onboarding_upgrade' => [
        'label'       => 'Onboarding — Dag 7: Upgrade naar Premium',
        'description' => 'Proef loopt af — zet aan tot een abonnement.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Je proefperiode loopt af — behoud alle functies',
            'en' => 'Your trial is ending — keep all features',
            'de' => 'Deine Testphase endet — behalte alle Funktionen',
            'es' => 'Tu período de prueba está por terminar — conserva todas las funciones',
            'fr' => 'Votre période d\'essai se termine — conservez toutes les fonctionnalités',
            'uk' => 'Ваш пробний період спливає — збережіть усі функції',
            'pl' => 'Twój okres próbny się kończy — zachowaj wszystkie funkcje',
            'it' => 'La tua prova sta per scadere — mantieni tutte le funzioni',
            'pt' => 'O teu período de teste está a terminar — mantém todas as funcionalidades',
            'lt' => 'Jūsų bandomasis laikotarpis baigiasi — išsaugokite visas funkcijas',
            'ja' => '無料期間終了間近 — すべての機能を維持しましょう',
            'tr' => 'Deneme süren doluyor — tüm özellikleri koru',
        ],
        'view'        => 'emails.campaigns.onboarding_upgrade',
    ],

    'onboarding_discount' => [
        'label'       => 'Onboarding — Dag 14: Tijdelijke korting',
        'description' => 'Laatste zetje met een tijdelijke actie.',
        'category'    => 'productinfo',
        'locales'     => ['nl', 'en', 'de', 'es', 'fr', 'uk', 'pl', 'it', 'pt', 'lt', 'ja', 'tr'],
        'subjects'    => [
            'nl' => 'Tijdelijk: stap nu over op MilMap Premium',
            'en' => 'Limited time: upgrade to MilMap Premium now',
            'de' => 'Zeitlich begrenzt: Steig jetzt auf MilMap Premium um',
            'es' => 'Por tiempo limitado: pásate ahora a MilMap Premium',
            'fr' => 'Offre limitée : passez dès maintenant à MilMap Premium',
            'uk' => 'Тимчасово: перейдіть зараз на MilMap Premium',
            'pl' => 'Tymczasowo: przejdź teraz na MilMap Premium',
            'it' => 'A tempo limitato: passa ora a MilMap Premium',
            'pt' => 'Por tempo limitado: muda agora para o MilMap Premium',
            'lt' => 'Laikinai: pereikite į MilMap „Premium“ dabar',
            'ja' => '期間限定：今すぐMilMap Premiumに切り替えましょう',
            'tr' => 'Sınırlı süre: şimdi MilMap Premium\'a geç',
        ],
        'view'        => 'emails.campaigns.onboarding_discount',
    ],
];
