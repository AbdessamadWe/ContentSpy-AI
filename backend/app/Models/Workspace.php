<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    protected $fillable = [
        'ulid', 'owner_id', 'name', 'slug', 'plan', 'plan_expires_at',
        'credits_balance', 'credits_reserved', 'stripe_customer_id',
        'stripe_subscription_id', 'white_label', 'custom_domain',
        'custom_logo', 'settings', 'is_active',
    ];

    protected $casts = [
        'plan_expires_at'  => 'datetime',
        'white_label'      => 'boolean',
        'is_active'        => 'boolean',
        'settings'         => 'array',
        'credits_balance'  => 'integer',
        'credits_reserved' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $workspace) {
            if (empty($workspace->ulid)) {
                $workspace->ulid = Str::ulid()->toBase32();
            }
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name) . '-' . Str::random(6);
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot('role', 'invited_by', 'accepted_at')
            ->withTimestamps();
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function tokenUsageLogs(): HasMany
    {
        return $this->hasMany(TokenUsageLog::class);
    }

    /** Available credits = balance - reserved */
    public function getAvailableCreditsAttribute(): int
    {
        return max(0, $this->credits_balance - $this->credits_reserved);
    }

    public function hasEnoughCredits(int $amount): bool
    {
        return $this->available_credits >= $amount;
    }

    public function isOnPlan(string ...$plans): bool
    {
        return in_array($this->plan, $plans);
    }

    public function getPlanLimit(string $key): mixed
    {
        return config("contentspy.plans.{$this->plan}.{$key}");
    }
}
