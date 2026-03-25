<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'workspace_id', 'url', 'secret', 'events',
        'is_active', 'last_triggered_at', 'failure_count',
    ];

    protected $casts = [
        'events'            => 'array',
        'is_active'         => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Auto-disable after 10 consecutive delivery failures */
    public function shouldAutoDisable(): bool
    {
        return $this->failure_count >= 10;
    }
}
