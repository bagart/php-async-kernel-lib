<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

use BAGArt\AsyncKernel\Contracts\ASKProducerContract;

interface WithASKProducerContract
{
    /**
     * @return ASKProducerContract[]
     */
    public function producers(): array;
}
