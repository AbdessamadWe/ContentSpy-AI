<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentSuggestion extends Model
{
    protected $fillable = [
        'workspace_id', 'site_id', 'detection_id',
        'suggested_title', 'content_angle', 'target_keywords',
        'recommended_word_count', 'tone', 'h2_structure',
        'estimated_traffic', 'keyword_difficulty', 'opportunity_score',
        'status', 'scheduled_for', 'rejected_reason', 'article_id', 'expires_at',
    ];

    protected $casts = [
        'target_keywords' => 'array',
        'h2_structure'    => 'array',
        'scheduled_for'   => 'datetime',
        'expires_at'      => 'datetime',
        'opportunity_score' => 'integer',
        'recommended_word_count' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        // Auto-set expires_at to +30 days on create
        static::creating(function (self $suggestion) {
            if (empty($suggestion->expires_at)) {
                $suggestion->expires_at = now()->addDays(
                    config('contentspy.suggestion_expiry_days', 30)
                );
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(SpyDetection::class, 'detection_id');
    }

    public function article(): HasOne
    {
        return $this->hasOne(Article::class, 'suggestion_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
