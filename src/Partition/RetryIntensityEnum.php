<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Partition;

enum RetryIntensityEnum: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    /**
     * @return list<int>
     */
    public function backoffSeconds(): array
    {
        return match ($this) {
            self::LOW => [1, 3, 5, 10, 30],
            self::MEDIUM => [1, 5, 10, 30, 60],
            self::HIGH => [1, 10, 30, 60, 300],
        };
    }
}
