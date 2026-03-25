<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpyDetection extends Model
{
    protected $fillable = [
        'competitor_id', 'site_id', 'workspace_id', 'method',
        'source_url', 'title', 'excerpt', 'author', 'published_at',
        'categories', 'tags', 'content_hash', 'opportunity_score',
        'keyword_difficulty', 'estimated_traffic', 'raw_data',
        'status', 'suggestion_id', 'credits_consumed',
    ];

    protected $casts = [
        'published_at'  => 'datetime',
        'categories'    => 'array',
        'tags'          => 'array',
        'raw_data'      => 'array',
        'opportunity_score' => 'integer',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(ContentSuggestion::class, 'suggestion_id');
    }

    /**
     * Find or create by content_hash. Returns [model, wasRecentlyCreated].
     * content_hash = SHA-256 of normalized URL + title.
     */
    public static function findOrCreateByHash(array $attributes): static
    {
        $hash = hash('sha256', strtolower(trim($attributes['source_url'] ?? '')) . '|' . strtolower(trim($attributes['title'] ?? '')));
        return static::firstOrCreate(
            ['content_hash' => $hash],
            array_merge($attributes, ['content_hash' => $hash]),
        );
    }

    public static function generateHash(string $url, string $title = ''): string
    {
        // Normalize: lowercase, strip UTM params, strip trailing slash
        $url = strtolower(rtrim(preg_replace('/[?&]utm_[^&]*/', '', $url), '/'));
        return hash('sha256', $url . '|' . strtolower(trim($title)));
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }
}
