<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    // APPEND-ONLY — never update or delete rows
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'workspace_id', 'user_id', 'type', 'amount', 'balance_after',
        'action_type', 'action_id', 'description', 'metadata',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'balance_after' => 'integer',
        'metadata'     => 'array',
        'created_at'   => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeCredits($query)
    {
        return $query->whereIn('type', ['purchase', 'plan_grant', 'refund', 'adjustment']);
    }
}
