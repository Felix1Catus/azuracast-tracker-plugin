<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Entity\Enums;

enum SessionEndReason: string
{
    case Logout = 'logout';           // the player logged out
    case Timeout = 'timeout';         // no stream and no heartbeat for too long
    case Replaced = 'replaced';       // the branch logged in on another device (one device per branch)
    case DailyReset = 'daily_reset';  // nightly reset
    case Admin = 'admin';             // ended by an admin action (PIN changed, branch disabled or deleted)
}
