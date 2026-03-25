<?php
namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PermissionService;

class SitePolicy
{
    public function __construct(private PermissionService $permissions) {}

    private function workspace(Site $site): Workspace
    {
        return Workspace::findOrFail($site->workspace_id);
    }

    public function view(User $user, Site $site): bool
    {
        return $this->permissions->can($user, 'view', $this->workspace($site));
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'manage_sites', $workspace);
    }

    public function update(User $user, Site $site): bool
    {
        return $this->permissions->can($user, 'manage_sites', $this->workspace($site));
    }

    public function delete(User $user, Site $site): bool
    {
        return $this->permissions->can($user, 'manage_sites', $this->workspace($site));
    }
}
