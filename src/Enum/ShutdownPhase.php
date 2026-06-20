<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Enum;

enum ShutdownPhase
{
    case RUNNING;
    case STOPPING;
    case DRAINING;
    case FORCING;
    case STOPPED;
}
