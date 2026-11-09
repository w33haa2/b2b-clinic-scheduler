<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public site SEO
    |--------------------------------------------------------------------------
    |
    | Used by PublicSiteController and resources/views/app.blade.php for the
    | landing page's meta description, Open Graph / Twitter cards and JSON-LD.
    | Titles come from the page's <Head> (app name is appended in app.ts).
    |
    */

    'description' => env(
        'SEO_DESCRIPTION',
        'Scheduling, patient records, billing and invoicing for clinics. One shared calendar for your whole team, payments that keep their history, and invoices in seconds.',
    ),

    // Relative to APP_URL. 1200×630 PNG in public/.
    'og_image' => '/og-image.png',

    // Where "Book a demo" goes (a mailto: link on the landing page).
    'contact_email' => env('CONTACT_EMAIL', 'hello@example.com'),

    // Optional, e.g. "@clinicapp". Leave empty to omit twitter:site.
    'twitter_site' => env('SEO_TWITTER_SITE'),

];
