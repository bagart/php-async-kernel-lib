<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Promise;

use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;

interface ASKPromiseResolverContract extends ASKTickableContract
{
    public function isReady(): bool;

    public function await(
        ASKPromiseContract $promise,
        int $timeout = 0,
    ): mixed;

    public function wait(ASKPromiseContract $promise): void;
}
