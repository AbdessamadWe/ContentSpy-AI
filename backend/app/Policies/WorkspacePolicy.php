<?php
namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PermissionService;

class WorkspacePolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'view', $workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'manage_sites', $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'delete_workspace', $workspace);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'manage_billing', $workspace);
    }

    public function inviteMembers(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'invite_members', $workspace);
    }
}
