<?php
namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Generic raw data store for spy detections.
 * Stores the full payload from any spy method for audit/debug.
 * TTL: 30 days (enforced via MongoDB TTL index on created_at).
 */
class SpyRawData extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'spy_raw_data';

    public $timestamps = true;  // created_at used by TTL index

    protected $fillable = [
        'detection_id',
        'competitor_id',
        'workspace_id',
        'method',       // rss, sitemap, html_scraping, google_news, social_signal, keyword_gap, serp
        'source_url',
        'raw_payload',  // full API/scrape response
        'processed_at',
    ];

    protected $casts = [
        'raw_payload'  => 'array',
        'processed_at' => 'datetime',
    ];

    public static function storeForDetection(int $detectionId, int $competitorId, int $workspaceId, string $method, string $sourceUrl, array $rawPayload): static
    {
        return static::create([
            'detection_id'  => $detectionId,
            'competitor_id' => $competitorId,
            'workspace_id'  => $workspaceId,
            'method'        => $method,
            'source_url'    => $sourceUrl,
            'raw_payload'   => $rawPayload,
            'processed_at'  => now(),
        ]);
    }
}
