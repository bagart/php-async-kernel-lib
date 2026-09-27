<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Queue;

interface RetryQueueSizeContract
{
    /**
     * Size of the retry queue as a whole — deliberately not size($queueName):
     * ASKQueueAdapterContract::size() takes the queue name, so a shared method
     * name with a different signature would make the two contracts
     * impossible for one class to implement.
     */
    public function retryQueueSize(): int;
}
