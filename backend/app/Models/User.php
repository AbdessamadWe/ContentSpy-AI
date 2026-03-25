<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'google_id', 'avatar',
        'timezone', 'locale', 'is_active', 'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token', 'google_id'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
    ];

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot('role', 'invited_by', 'accepted_at')
            ->withTimestamps();
    }

    public function ownedWorkspaces()
    {
        return Workspace::where('owner_id', $this->id);
    }

    /** Returns the first workspace the user belongs to (used after login) */
    public function primaryWorkspace(): ?Workspace
    {
        return $this->workspaces()->orderBy('workspace_users.created_at')->first();
    }

    /** Check if user has a specific role in a workspace */
    public function roleInWorkspace(int $workspaceId): ?string
    {
        return $this->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->first()?->pivot?->role;
    }

    public function canManageWorkspace(int $workspaceId): bool
    {
        return in_array($this->roleInWorkspace($workspaceId), ['owner', 'admin']);
    }
}
