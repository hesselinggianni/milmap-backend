<?php

/*
 * Admin → Server: wat de statuspagina controleert. Zie App\Services\ServerStatus.
 */
return [
    // Map met deploy-back-ups (BACKUP_PATH in milmap-deploy/config.sh).
    'backup_path' => env('SERVER_STATUS_BACKUP_PATH', '/home/milmap/milmap-backups'),
    // Bewaarbeleid per onderdeel (BACKUP_KEEP in milmap-deploy/config.sh); alleen ter info.
    'backup_keep' => (int) env('SERVER_STATUS_BACKUP_KEEP', 2),

    // systemd-services die 'active' moeten zijn (systemctl is-active werkt zonder sudo).
    'services' => ['nginx', 'mysqld', 'php-fpm84', 'exim', 'dovecot', 'directadmin'],

    // Publieke domeinen: HTTP-check + SSL-vervaldatum.
    'domains' => [
        ['name' => 'milmap.nl',          'url' => 'https://milmap.nl/'],
        ['name' => 'app.milmap.nl',      'url' => 'https://app.milmap.nl/'],
        ['name' => 'api.milmap.nl',      'url' => 'https://api.milmap.nl/up'],
        ['name' => 'admin.milmap.nl',    'url' => 'https://admin.milmap.nl/'],
        ['name' => 'connect.milmap.nl',  'url' => 'https://connect.milmap.nl/'],
        ['name' => 'partners.milmap.nl', 'url' => 'https://partners.milmap.nl/'],
        ['name' => 'legal.milmap.nl',    'url' => 'https://legal.milmap.nl/'],
        ['name' => 'store.milmap.nl',    'url' => 'https://store.milmap.nl/'],
    ],
];
