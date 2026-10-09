<?php

declare(strict_types=1);

namespace Plugin\BranchTracker;

/**
 * Station-level permissions added through App\Event\BuildPermissions. They show up in
 * Administration → Roles like core ones. "administer all" (super admin / station admin) covers them.
 */
final class Permissions
{
    public const string VIEW = 'view branches';
    public const string MANAGE = 'manage branches';

    public const array NAMES = [
        self::VIEW => 'View Branches',
        self::MANAGE => 'Manage Branches (create, edit, PINs)',
    ];
}
