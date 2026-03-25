<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsageLog extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'workspace_id', 'user_id', 'site_id', 'action_type', 'model', 'provider',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'images_count', 'video_seconds', 'cost_usd', 'credits_consumed',
        'article_id', 'job_id', 'request_id', 'metadata',
    ];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens'      => 'integer',
        'images_count'      => 'integer',
        'video_seconds'     => 'integer',
        'cost_usd'          => 'decimal:6',
        'credits_consumed'  => 'integer',
        'metadata'          => 'array',
        'created_at'        => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /** Total cost in USD for a workspace in a given period */
    public static function totalCostForWorkspace(int $workspaceId, string $from = null, string $to = null): float
    {
        $query = static::where('workspace_id', $workspaceId);
        if ($from) $query->where('created_at', '>=', $from);
        if ($to)   $query->where('created_at', '<=', $to);
        return (float) $query->sum('cost_usd');
    }
}
