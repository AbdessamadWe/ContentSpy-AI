<?php
namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Single entry point for all workspace permission checks.
 * Never inline role checks elsewhere — always go through this service.
 */
class PermissionService
{
    public function getUserRole(User $user, Workspace $workspace): ?WorkspaceRole
    {
        $role = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()?->pivot?->role;

        return $role ? WorkspaceRole::from($role) : null;
    }

    public function can(User $user, string $ability, Workspace $workspace): bool
    {
        $role = $this->getUserRole($user, $workspace);
        if (!$role) return false;

        return match($ability) {
            'manage_billing'   => $role->canManageBilling(),
            'manage_sites'     => $role->canManageSites(),
            'publish'          => $role->canPublish(),
            'create_content'   => $role->canCreateContent(),
            'view'             => $role->canView(),
            'invite_members'   => $role->isAtLeast(WorkspaceRole::Admin),
            'delete_workspace' => $role === WorkspaceRole::Owner,
            'manage_workflow'  => $role->isAtLeast(WorkspaceRole::Admin),
            'full_autopilot'   => $role->isAtLeast(WorkspaceRole::Admin),
            default            => false,
        };
    }

    public function canOrFail(User $user, string $ability, Workspace $workspace): void
    {
        if (!$this->can($user, $ability, $workspace)) {
            abort(403, "You don't have permission to perform this action.");
        }
    }
}
