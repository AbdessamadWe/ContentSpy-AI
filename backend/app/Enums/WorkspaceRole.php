<?php
namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Editor = 'editor';
    case Writer = 'writer';
    case Viewer = 'viewer';

    /** Can this role manage billing and workspace settings? */
    public function canManageBilling(): bool
    {
        return in_array($this, [self::Owner]);
    }

    /** Can manage sites, competitors, and workspace config */
    public function canManageSites(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }

    /** Can approve/reject suggestions and publish articles */
    public function canPublish(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor]);
    }

    /** Can create/edit articles and accept suggestions */
    public function canCreateContent(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor, self::Writer]);
    }

    /** Can only read data */
    public function canView(): bool
    {
        return true; // all roles can view
    }

    public function isAtLeast(self $role): bool
    {
        $hierarchy = [
            self::Owner->value  => 5,
            self::Admin->value  => 4,
            self::Editor->value => 3,
            self::Writer->value => 2,
            self::Viewer->value => 1,
        ];
        return ($hierarchy[$this->value] ?? 0) >= ($hierarchy[$role->value] ?? 0);
    }
}
