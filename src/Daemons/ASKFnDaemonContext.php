<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Daemons;

use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

final class ASKFnDaemonContext
{
    public function __construct(
        public readonly string $daemonName,
        public readonly ?ASKSchedulerContract $scheduler = null,
        public readonly ASKLogWrapper $logger = new ASKLogWrapper(),
        public array $payload = [],
    ) {
    }
}
