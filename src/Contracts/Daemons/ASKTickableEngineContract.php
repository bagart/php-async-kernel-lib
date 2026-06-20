<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

use BAGArt\AsyncKernel\ASKShutdownContext;

interface ASKTickableEngineContract extends ASKTickableContract
{
    public function shutdown(ASKShutdownContext $context): bool;
}
