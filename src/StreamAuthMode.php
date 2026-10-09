<?php

declare(strict_types=1);

namespace Plugin\BranchTracker;

/** How the station's mounts treat listeners (set with `branch-tracker:stream-auth`). */
enum StreamAuthMode: string
{
    case Off = 'off';         // no Icecast auth at all (stock behaviour)
    case Observe = 'observe'; // everyone may listen; branch tokens are recorded (pilot / parallel run)
    case Enforce = 'enforce'; // only a valid branch session token may listen
}
