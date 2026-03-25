<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    protected $fillable = [
        'site_id', 'workspace_id', 'platform', 'platform_account_id',
        'account_name', 'access_token', 'refresh_token', 'token_expires_at',
        'page_id', 'board_ids', 'is_active',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'board_ids'        => 'array',
        'is_active'        => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function needsTokenRefresh(int $bufferMinutes = 15): bool
    {
        return $this->token_expires_at && $this->token_expires_at->subMinutes($bufferMinutes)->isPast();
    }
}
