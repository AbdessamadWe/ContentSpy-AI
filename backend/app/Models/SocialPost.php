<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    protected $fillable = [
        'article_id', 'social_account_id', 'workspace_id', 'platform', 'post_type',
        'caption', 'hashtags', 'media_urls', 'video_url', 'platform_post_id',
        'status', 'scheduled_for', 'published_at', 'retry_count', 'error_message',
        'credits_consumed', 'metrics', 'metrics_updated_at',
    ];

    protected $casts = [
        'media_urls'         => 'array',
        'metrics'            => 'array',
        'scheduled_for'      => 'datetime',
        'published_at'       => 'datetime',
        'metrics_updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function canRetry(): bool
    {
        return $this->retry_count < config('contentspy.publish_max_retries', 3);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
