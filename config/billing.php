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

    /*
    |--------------------------------------------------------------------------
    | AppSumo Lifetime Deal
    |--------------------------------------------------------------------------
    | Stripe PRODUCT id waar een verzilverde AppSumo-code een 100%-korting-
    | abonnement op aanmaakt. De bijbehorende Price wordt runtime uit het product
    | opgehaald (default_price, anders de eerste actieve recurring price), of kan
    | expliciet worden vastgezet via STRIPE_APPSUMO_PRICE.
    */
    'appsumo_product' => env('STRIPE_APPSUMO_PRODUCT', 'prod_UenLnnsGGFRZZI'),
    'appsumo_price'   => env('STRIPE_APPSUMO_PRICE'),

    /*
    |--------------------------------------------------------------------------
    | Checkout Payment Method Types
    |--------------------------------------------------------------------------
    | Comma-separated list of payment methods that Checkout may offer, e.g.
    | "card,ideal,bancontact". A non-empty list is passed to Stripe as
    | `payment_method_types`, guaranteeing the session always has at least one
    | valid method for its currency. Leave EMPTY to let Stripe Checkout pick the
    | methods configured in your Dashboard (dynamic payment methods) — note that
    | this requires the Dashboard to be set up, otherwise Stripe returns
    | "No valid payment method types for this Checkout Session".
    |
    | Default 'card' works out of the box for every currency/account.
    */
    'payment_method_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STRIPE_PAYMENT_METHOD_TYPES', 'card'))
    ))),

];
