<?php
namespace App\Policies;

use App\Models\Article;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PermissionService;

class ArticlePolicy
{
    public function __construct(private PermissionService $permissions) {}

    private function workspace(Article $article): Workspace
    {
        return Workspace::findOrFail($article->workspace_id);
    }

    public function view(User $user, Article $article): bool
    {
        return $this->permissions->can($user, 'view', $this->workspace($article));
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->permissions->can($user, 'create_content', $workspace);
    }

    public function update(User $user, Article $article): bool
    {
        return $this->permissions->can($user, 'create_content', $this->workspace($article));
    }

    public function approve(User $user, Article $article): bool
    {
        return $this->permissions->can($user, 'publish', $this->workspace($article));
    }

    public function publish(User $user, Article $article): bool
    {
        return $this->permissions->can($user, 'publish', $this->workspace($article));
    }

    public function delete(User $user, Article $article): bool
    {
        return $this->permissions->can($user, 'manage_sites', $this->workspace($article));
    }
}
