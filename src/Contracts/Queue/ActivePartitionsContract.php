<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Queue;

interface ActivePartitionsContract
{
    public function count(): int;
}
