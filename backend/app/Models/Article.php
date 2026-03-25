<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Exceptions\InvalidStateTransitionException;

class Article extends Model
{
    // Pipeline state machine
    const PIPELINE_STEPS = ['pending', 'outline', 'writing', 'seo', 'images', 'review', 'ready', 'failed'];

    const PIPELINE_TRANSITIONS = [
        'pending'  => ['outline', 'failed'],
        'outline'  => ['writing', 'failed'],
        'writing'  => ['seo', 'failed'],
        'seo'      => ['images', 'failed'],
        'images'   => ['review', 'ready', 'failed'],
        'review'   => ['ready', 'failed'],
        'ready'    => ['failed'],
        'failed'   => ['pending'],
    ];

    protected $fillable = [
        'workspace_id', 'site_id', 'suggestion_id', 'title', 'slug', 'content',
        'formatted_content', 'excerpt', 'meta_title', 'meta_description',
        'focus_keyword', 'target_keywords', 'content_angle', 'categories', 'tags',
        'word_count', 'word_count_target', 'tone', 'ai_model_text', 'ai_model_image',
        'generate_images', 'auto_publish', 'credit_reservation', 'credits_reserved',
        'failure_reason', 'featured_image_url', 'featured_image_r2_key',
        'inline_images', 'outline',
        'total_tokens_used', 'total_cost_usd', 'total_credits_consumed',
        'generation_status', 'review_status', 'duplicate_check_passed',
        'duplicate_score', 'wp_post_id', 'wp_published_at', 'wp_post_url',
        'publish_status', 'scheduled_for',
    ];

    protected $casts = [
        'target_keywords'        => 'array',
        'inline_images'          => 'array',
        'outline'                => 'array',
        'categories'             => 'array',
        'tags'                   => 'array',
        'duplicate_check_passed' => 'boolean',
        'generate_images'        => 'boolean',
        'auto_publish'           => 'boolean',
        'wp_published_at'        => 'datetime',
        'scheduled_for'          => 'datetime',
        'total_cost_usd'         => 'decimal:4',
        'duplicate_score'        => 'decimal:2',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(ContentSuggestion::class, 'suggestion_id');
    }

    public function tokenUsageLogs(): HasMany
    {
        return $this->hasMany(TokenUsageLog::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * Transition the pipeline to the next step.
     * Throws InvalidStateTransitionException for invalid transitions.
     */
    public function advancePipelineStep(string $nextStep): void
    {
        $allowed = static::PIPELINE_TRANSITIONS[$this->generation_status] ?? [];
        if (!in_array($nextStep, $allowed)) {
            throw new InvalidStateTransitionException(
                "Cannot transition article #{$this->id} from [{$this->generation_status}] to [{$nextStep}]"
            );
        }
        $this->update(['generation_status' => $nextStep]);
    }

    public function getTotalCostUsdAttribute(): float
    {
        return (float) $this->attributes['total_cost_usd'];
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeReadyToPublish($query)
    {
        return $query->where('generation_status', 'ready')
            ->where('publish_status', 'draft')
            ->where('duplicate_check_passed', true);
    }
}
