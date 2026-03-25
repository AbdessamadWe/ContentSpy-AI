<?php
namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class HtmlSnapshot extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'html_snapshots';

    protected $fillable = [
        'competitor_id',
        'workspace_id',
        'blog_url',
        'links',        // array of discovered article URLs
        'link_count',
        'screenshot_base64',
        'fetched_at',
    ];

    protected $casts = [
        'links'      => 'array',
        'link_count' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public static function latestForCompetitor(int $competitorId): ?static
    {
        return static::where('competitor_id', $competitorId)
            ->orderBy('fetched_at', 'desc')
            ->first();
    }

    /** Return links in this snapshot not seen in previous snapshot */
    public function newLinks(?self $previous): array
    {
        if (!$previous) return $this->links ?? [];
        $old = $previous->links ?? [];
        return array_values(array_diff($this->links ?? [], $old));
    }
}
