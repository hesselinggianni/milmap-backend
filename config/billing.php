<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    | Set these in your .env file. Get them from https://dashboard.stripe.com/apikeys
    */
    'stripe_key'    => env('STRIPE_KEY'),
    'stripe_secret' => env('STRIPE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhook Secret
    |--------------------------------------------------------------------------
    | Get this from the Stripe Dashboard → Webhooks → Signing secret.
    | Required for verifying incoming webhook events.
    */
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Price IDs
    |--------------------------------------------------------------------------
    | Create products + prices in your Stripe Dashboard and paste the Price IDs
    | (price_xxx) here via .env.
    |
    | Naming convention: STRIPE_PRICE_{PLAN}_{INTERVAL}
    */
    'stripe_price_pro_monthly'  => env('STRIPE_PRICE_PRO_MONTHLY'),
    'stripe_price_pro_yearly'   => env('STRIPE_PRICE_PRO_YEARLY'),
    'stripe_price_team_monthly' => env('STRIPE_PRICE_TEAM_MONTHLY'),
    'stripe_price_team_yearly'  => env('STRIPE_PRICE_TEAM_YEARLY'),

];
