<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Job;

enum JobState: string
{
    case NEW = 'new';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case RETRY = 'retry';
    case DEAD_LETTER = 'dead_letter';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::DEAD_LETTER => true,
            default => false,
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RUNNING, self::RETRY => true,
            default => false,
        };
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NEW => [self::RUNNING],
            self::RUNNING => [self::COMPLETED, self::FAILED, self::RETRY],
            self::RETRY => [self::RUNNING, self::DEAD_LETTER],
            self::FAILED => [self::DEAD_LETTER, self::RUNNING],
            self::COMPLETED, self::DEAD_LETTER => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
