<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Enum;

enum ExceptionPolicy
{
    case IGNORE;
    case RESTART_DAEMON;
    case STOP_KERNEL;
    case INTERRUPT;
}
