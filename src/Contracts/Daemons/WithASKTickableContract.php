<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

interface WithASKTickableContract
{
    /**
     * @return ASKTickableContract[]
     */
    public function tickable(): array;
}
