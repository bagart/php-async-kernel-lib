<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface ASKSocketSchedulerContract
{
    public function watchRead(mixed $socket): ASKResourceLease;

    public function unwatchRead(mixed $socket): void;

    public function unwatchReadByResourceId(int $socketId): void;

    public function watchWrite(mixed $socket): ASKResourceLease;

    public function unwatchWrite(mixed $socket): void;

    public function unwatchWriteByResourceId(int $socketId): void;
}
