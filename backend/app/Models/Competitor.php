<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competitor extends Model
{
    protected $fillable = [
        'site_id', 'workspace_id', 'name', 'domain',
        'rss_url', 'sitemap_url', 'blog_url',
        'twitter_handle', 'instagram_handle', 'semrush_domain',
        'active_methods', 'auto_spy', 'auto_spy_interval',
        'confidence_threshold_suggest', 'confidence_threshold_generate',
        'confidence_threshold_publish', 'last_scanned_at',
        'total_articles_detected', 'is_active',
    ];

    protected $casts = [
        'active_methods'  => 'array',
        'auto_spy'        => 'boolean',
        'is_active'       => 'boolean',
        'last_scanned_at' => 'datetime',
        'confidence_threshold_suggest'  => 'integer',
        'confidence_threshold_generate' => 'integer',
        'confidence_threshold_publish'  => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function detections(): HasMany
    {
        return $this->hasMany(SpyDetection::class)->orderByDesc('created_at');
    }

    public function spyJobLogs(): HasMany
    {
        return $this->hasMany(SpyJobLog::class);
    }

    public function scopeWithAutoSpy($query)
    {
        return $query->where('auto_spy', true)->where('is_active', true);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function hasMethod(string $method): bool
    {
        return in_array($method, $this->active_methods ?? []);
    }
}
