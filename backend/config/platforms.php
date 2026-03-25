<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Social Media Platform Configuration
    |--------------------------------------------------------------------------
    */

    'facebook' => [
        'label'             => 'Facebook',
        'max_posts_per_day' => 25,
        'post_types'        => ['link', 'photo', 'carousel'],
        'max_caption_chars' => 63206,
        'api_version'       => 'v18.0',
    ],

    'instagram' => [
        'label'             => 'Instagram',
        'max_posts_per_day' => 25,
        'post_types'        => ['image', 'carousel', 'reel'],
        'max_caption_chars' => 2200,
        'max_hashtags'      => 30,
        'reel_min_seconds'  => 3,
        'reel_max_seconds'  => 90,
        'reel_aspect_ratio' => '9:16',
        'reel_resolution'   => '1080x1920',
        'requires_business_account' => true,
    ],

    'tiktok' => [
        'label'             => 'TikTok',
        'max_posts_per_day' => 5,
        'post_types'        => ['video'],
        'max_caption_chars' => 2200,
        'video_min_seconds' => 15,
        'video_max_seconds' => 600,
        'video_codec'       => 'h264',
        'audio_codec'       => 'aac',
        'max_resolution'    => '1080x1920',
        'requires_file_upload' => true, // no URL upload — must pre-upload file
    ],

    'pinterest' => [
        'label'             => 'Pinterest',
        'max_posts_per_day' => 150,
        'post_types'        => ['pin', 'idea_pin'],
        'max_title_chars'   => 100,
        'max_description_chars' => 500,
        'recommended_image_ratio' => '2:3',
        'recommended_image_size'  => '1000x1500',
        'api_version'       => 'v5',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Retry Settings
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts'  => 3,
        'backoff_base'  => 2,   // exponential backoff seconds
        'refund_credits_on_final_failure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Video Output Specs (for FFmpeg service)
    |--------------------------------------------------------------------------
    */
    'video_specs' => [
        'tiktok' => [
            'width'      => 1080,
            'height'     => 1920,
            'fps'        => 30,
            'codec'      => 'libx264',
            'audio'      => 'aac',
            'format'     => 'mp4',
            'max_duration' => 600,
        ],
        'instagram_reels' => [
            'width'      => 1080,
            'height'     => 1920,
            'fps'        => 30,
            'codec'      => 'libx264',
            'audio'      => 'aac',
            'format'     => 'mp4',
            'min_duration' => 3,
            'max_duration' => 90,
        ],
    ],

];
