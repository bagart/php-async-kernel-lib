<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface ASKKernelSleepStrategy
{
    public function sleep(bool $madeProgress): void;
}
