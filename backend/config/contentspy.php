<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ContentSpy AI — Platform Settings
    |--------------------------------------------------------------------------
    */

    'auto_spy_min_credits' => env('AUTO_SPY_MIN_CREDITS', 50),

    'suggestion_expiry_days' => env('SUGGESTION_EXPIRY_DAYS', 30),

    'audit_log_retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 90),

    'max_article_word_count' => env('MAX_ARTICLE_WORD_COUNT', 10000),

    'chunked_generation_threshold' => env('CHUNKED_GENERATION_THRESHOLD', 2000),

    'duplicate_check_similarity_threshold' => env('DUPLICATE_CHECK_SIMILARITY_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Plan Limits
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'starter' => [
            'credits_per_month'      => 500,
            'max_sites'              => 3,
            'max_competitors_per_site' => 5,
            'max_social_platforms'   => 2,
            'max_articles_per_day'   => 10,
            'full_autopilot'         => false,
            'white_label'            => false,
            'price_monthly_usd'      => 19.00,
        ],
        'pro' => [
            'credits_per_month'      => 2000,
            'max_sites'              => 15,
            'max_competitors_per_site' => 50,
            'max_social_platforms'   => 4,
            'max_articles_per_day'   => 100,
            'full_autopilot'         => true,
            'white_label'            => false,
            'price_monthly_usd'      => 49.00,
        ],
        'agency' => [
            'credits_per_month'      => 10000,
            'max_sites'              => null,   // unlimited
            'max_competitors_per_site' => null,
            'max_social_platforms'   => null,
            'max_articles_per_day'   => null,
            'full_autopilot'         => true,
            'white_label'            => true,
            'price_monthly_usd'      => 149.00,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit Packs (one-time purchases)
    |--------------------------------------------------------------------------
    */
    'credit_packs' => [
        ['credits' => 200,  'price_usd' => 9.00],
        ['credits' => 1000, 'price_usd' => 39.00],
        ['credits' => 5000, 'price_usd' => 159.00],
    ],

    'credit_overage_price_usd' => 0.05,  // per credit

    /*
    |--------------------------------------------------------------------------
    | Microservice URLs
    |--------------------------------------------------------------------------
    */
    'playwright_url' => env('PLAYWRIGHT_URL', 'http://playwright:3001'),
    'ffmpeg_url'     => env('FFMPEG_URL', 'http://ffmpeg:3002'),

    /*
    |--------------------------------------------------------------------------
    | Publishing Retry Settings
    |--------------------------------------------------------------------------
    */
    'publish_max_retries'       => 3,
    'publish_retry_backoff_base' => 2, // exponential backoff base (seconds)

    /*
    |--------------------------------------------------------------------------
    | WordPress Plugin Settings
    |--------------------------------------------------------------------------
    */
    'plugin' => [
        'slug'            => 'contentspy-connect',
        'min_wp_version'  => '5.8',
        'min_php_version' => '8.0',
        'current_version' => env('PLUGIN_VERSION', '1.0.0'),
        'download_bucket_path' => 'plugins/contentspy-connect.zip',
    ],
];
