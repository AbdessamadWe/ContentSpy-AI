<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will process requests from all domains.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the endpoints of Horizon internally.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Storage Driver
    |--------------------------------------------------------------------------
    |
    | This configuration options determines the storage driver that will
    | be used to store Horizon's data (failed jobs, metrics, etc). By
    | default, Redis will be used as your storage driver.
    |
    */

    'storage' => [
        'driver' => env('HORIZON_STORAGE_DRIVER', 'redis'),
        'redis' => [
            'connection' => env('HORIZON_REDIS_CONNECTION', 'default'),
            'chunk' => [
                'connection' => env('HORIZON_REDIS_CHUNK_CONNECTION', 'horizon'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Queue Connection
    |--------------------------------------------------------------------------
    |
    | Horizon can process your jobs in either a synchronous fashion, or can
    | process jobs using the specified queue connection. The default option
    | will process all jobs in the "default" queue connection.
    |
    */

    'use' => env('HORIZON_QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Times
    |--------------------------------------------------------------------------
    |
    | The queue wait times configuration tells Horizon how long to wait
    | before popping the next job off of the specified queue connection.
    | This is useful when a queue is processing a long-running job.
    |
    */

    'waits' => [
        'redis:default' => 30,
        'redis:high' => 10,
        'redis:medium' => 20,
        'redis:low' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Job Configuration
    |--------------------------------------------------------------------------
    |
    | The queue job configuration tells Horizon how to handle the various
    | queue jobs. This includes the number of times to retry a job and
    | the number of seconds to wait before retrying a job.
    |
    */

    'jobs' => [
        // High priority - publishing, critical operations
        'App\Jobs\Publishing\PublishToWordPressJob' => [
            'connection' => 'redis',
            'queue' => 'high',
            'tries' => 3,
            'timeout' => 300,
            'backoff' => [60, 180, 300],
        ],

        // Social publishing jobs
        'App\Jobs\Social\PublishToFacebookJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 3,
            'timeout' => 180,
            'backoff' => [60, 180, 300],
        ],
        'App\Jobs\Social\PublishToInstagramJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 3,
            'timeout' => 180,
            'backoff' => [60, 180, 300],
        ],
        'App\Jobs\Social\PublishToTikTokJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 3,
            'timeout' => 300,
            'backoff' => [60, 180, 300],
        ],
        'App\Jobs\Social\PublishToPinterestJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 3,
            'timeout' => 120,
            'backoff' => [60, 180, 300],
        ],

        // Content generation - CPU intensive
        'App\Jobs\Content\GenerateArticleJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 2,
            'timeout' => 600,
            'backoff' => [300, 600],
        ],
        'App\Jobs\Content\GenerateArticleImagesJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 2,
            'timeout' => 300,
            'backoff' => [120, 240],
        ],
        'App\Jobs\Content\GenerateArticleBodyJob' => [
            'connection' => 'redis',
            'queue' => 'medium',
            'tries' => 2,
            'timeout' => 400,
            'backoff' => [180, 360],
        ],

        // Spy jobs - can be slower
        'App\Jobs\Spy\RunSpyMethodJob' => [
            'connection' => 'redis',
            'queue' => 'low',
            'tries' => 1,
            'timeout' => 300,
        ],
        'App\Jobs\Spy\RunAutoSpyJob' => [
            'connection' => 'redis',
            'queue' => 'low',
            'tries' => 1,
            'timeout' => 600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure the Horizon worker processes. By default,
    | Horizon will launch the configured number of workers. You can
    | change this to match your server's CPU capacity.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['high', 'medium', 'low'],
                'balance' => 'simple',
                'maxProcesses' => 10,
                'minProcesses' => 2,
                'maxJobs' => 0,
                'timeout' => 60,
                'sleep' => 5,
                'memory' => 256,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['high', 'medium', 'low'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'minProcesses' => 1,
                'maxJobs' => 0,
                'timeout' => 60,
                'sleep' => 5,
                'memory' => 256,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Horizon can collect metrics on your jobs. These metrics include the
    | average amount of time a job takes to process. By default, Horizon
    | will collect metrics every minute.
    |
    */

    'metrics' => [
        'driver' => env('HORIZON_METRICS_DRIVER', 'redis'),
        'sample_rate' => env('HORIZON_METRICS_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trimming
    |--------------------------------------------------------------------------
    |
    | Horizon allows you to configure the trimming of various data types.
    | By default, all trimming is disabled. You can enable trimming
    | by setting the appropriate trim values to the desired duration.
    |
    */

    'trim' => [
        'recentJobs' => 1440, // 24 hours
        'failedJobs' => 10080, // 7 days
        'monitoredJobs' => 10080, // 7 days
        'metrics' => 10080, // 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Batching
    |--------------------------------------------------------------------------
    |
    | Queue batching allows you to process jobs in batches, which can be
    | useful when you have a large number of jobs that can be processed
    | in parallel.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Horizon can send notifications when jobs fail or when you exceed
    | your queue wait times. You can configure these notifications
    | to send emails or Slack messages.
    |
    */

    'notifications' => [
        'failed' => [
            'slack' => env('SLACK_WEBHOOK_URL'),
            'discord' => env('DISCORD_WEBHOOK_URL'),
        ],
    ],
];