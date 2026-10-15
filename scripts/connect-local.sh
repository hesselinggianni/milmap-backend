#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export APP_ENV=local APP_URL=http://127.0.0.1:8104 CONNECT_URL=http://localhost:8103
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/milmap-connect-local.sqlite
export CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=log QUEUE_CONNECTION=sync
export STRIPE_SECRET= STRIPE_KEY=
touch "$DB_DATABASE"
php artisan migrate --force
php scripts/connect-local-demo.php
exec php artisan serve --host=127.0.0.1 --port=8104
