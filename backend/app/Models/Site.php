<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'url', 'niche', 'language',
        'connection_type', 'wp_api_url', 'wp_username', 'wp_app_password',
        'plugin_api_key', 'plugin_secret', 'plugin_version',
        'wp_version', 'php_version', 'connection_status',
        'last_sync_at', 'last_post_at', 'ai_model_text', 'ai_model_image',
        'default_author_id', 'timezone', 'max_posts_per_day',
        'workflow_template', 'is_active', 'settings',
    ];

    protected $casts = [
        'last_sync_at'   => 'datetime',
        'last_post_at'   => 'datetime',
        'is_active'      => 'boolean',
        'settings'       => 'array',
        'max_posts_per_day' => 'integer',
    ];

    /** Encrypted attributes — stored AES-256, never logged */
    protected $hidden = ['wp_app_password', 'plugin_secret'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function contentSuggestions(): HasMany
    {
        return $this->hasMany(ContentSuggestion::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function workflowLogs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class);
    }

    public function isConnected(): bool
    {
        return $this->connection_status === 'connected';
    }

    /** Global scope — always filter by workspace */
    protected static function boot(): void
    {
        parent::boot();
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
