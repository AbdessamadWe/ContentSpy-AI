<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ContentSpy AI — Credit Costs per Action
    |--------------------------------------------------------------------------
    | Every action has a fixed credit cost defined here.
    | NEVER hardcode these values in job classes — always read from this config.
    |--------------------------------------------------------------------------
    */

    'actions' => [

        // ── Spy Actions ──────────────────────────────────────────────────────
        'rss_feed_scan'              => 1,
        'html_scraping_scan'         => 3,
        'sitemap_scan'               => 1,
        'google_news_scan'           => 2,
        'social_signal_scan'         => 2,
        'keyword_gap_pull'           => 5,
        'serp_monitoring_scan'       => 2,

        // ── Content Actions ──────────────────────────────────────────────────
        'content_suggestion_card'    => 2,
        'article_outline'            => 3,
        'article_generation_per_1000_words' => 5,
        'seo_optimization_pass'      => 2,
        'duplicate_content_check'    => 1,

        // ── Image Actions ────────────────────────────────────────────────────
        'image_dalle3'               => 3,
        'image_stable_diffusion'     => 2,
        'image_midjourney'           => 4,

        // ── Video / TTS Actions ──────────────────────────────────────────────
        'video_assembly_ffmpeg'      => 4,
        'tts_per_1000_chars'         => 2,

        // ── Publishing Actions ───────────────────────────────────────────────
        'wordpress_publish'          => 1,
        'facebook_post'              => 1,
        'instagram_post_image'       => 2,
        'instagram_reel'             => 5,
        'tiktok_video'               => 5,
        'pinterest_pin'              => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit Integrity Settings
    |--------------------------------------------------------------------------
    */
    'reservation_ttl_seconds' => 300,   // Reserved credits expire if job not started in 5min
    'min_balance_for_auto_spy' => 50,   // Auto-spy pauses below this balance

    /*
    |--------------------------------------------------------------------------
    | Credit Transaction Types
    |--------------------------------------------------------------------------
    */
    'transaction_types' => [
        'purchase',
        'plan_grant',
        'debit',
        'refund',
        'expiry',
        'adjustment',
    ],

];
