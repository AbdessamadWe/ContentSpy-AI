<?php
namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class SitemapSnapshot extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'sitemap_snapshots';

    protected $fillable = [
        'competitor_id',
        'workspace_id',
        'sitemap_url',
        'urls',         // array of {url, lastmod, priority}
        'url_count',
        'fetched_at',
    ];

    protected $casts = [
        'urls'      => 'array',
        'url_count' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public static function latestForCompetitor(int $competitorId): ?static
    {
        return static::where('competitor_id', $competitorId)
            ->orderBy('fetched_at', 'desc')
            ->first();
    }

    /** Return URLs present in this snapshot but not in previous snapshot */
    public function diffUrls(?self $previous): array
    {
        if (!$previous) return $this->urls ?? [];

        $previousUrls = array_column($previous->urls ?? [], 'url');
        return array_values(array_filter(
            $this->urls ?? [],
            fn($u) => !in_array($u['url'], $previousUrls),
        ));
    }
}
