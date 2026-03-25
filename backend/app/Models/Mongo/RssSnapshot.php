<?php
namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\Builder;

class RssSnapshot extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'rss_snapshots';

    protected $fillable = [
        'competitor_id',
        'workspace_id',
        'feed_url',
        'item_guids',   // array of guid/link strings — dedup keys
        'item_count',
        'raw_xml',      // stored for diff purposes
        'fetched_at',
    ];

    protected $casts = [
        'item_guids' => 'array',
        'item_count' => 'integer',
        'fetched_at' => 'datetime',
    ];

    /** Most recent snapshot for a competitor */
    public static function latestForCompetitor(int $competitorId): ?static
    {
        return static::where('competitor_id', $competitorId)
            ->orderBy('fetched_at', 'desc')
            ->first();
    }

    /** Return GUIDs in this snapshot that were NOT in the previous snapshot */
    public function newGuids(array $previousGuids): array
    {
        return array_values(array_diff($this->item_guids ?? [], $previousGuids));
    }
}
