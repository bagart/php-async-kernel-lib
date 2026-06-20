<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Wrappers;

use Psr\Log\LoggerInterface;
use Stringable;

final class ASKLogWrapper implements LoggerInterface
{
    public const LEVEL_DEFAULT = self::LEVEL_INFO;

    public const LEVEL_EMERGENCY = 'emergency';

    public const LEVEL_ALERT = 'alert';

    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_NOTICE = 'notice';

    public const LEVEL_INFO = 'info';

    public const LEVEL_DEBUG = 'debug';

    private const LEVEL_PRIORITY = [
        self::LEVEL_EMERGENCY => 0,
        self::LEVEL_ALERT => 1,
        self::LEVEL_CRITICAL => 2,
        self::LEVEL_ERROR => 3,
        self::LEVEL_WARNING => 4,
        self::LEVEL_NOTICE => 5,
        self::LEVEL_INFO => 6,
        self::LEVEL_DEBUG => 7,
    ];

    private readonly LoggerInterface $logger;
    private int $minLevel;

    public function __construct(
        ?LoggerInterface $logger = null,
        string $minLevel = self::LEVEL_INFO,
    ) {
        $this->logger = $logger ?? new \Monolog\Logger(
            'ask-log',
            [new \Monolog\Handler\StreamHandler('php://stderr')]
        );

        $this->minLevel = self::LEVEL_PRIORITY[$minLevel] ?? self::LEVEL_PRIORITY[self::LEVEL_DEFAULT];
    }

    private function shouldLog(string $level): bool
    {
        return (self::LEVEL_PRIORITY[$level] ?? 7) <= $this->minLevel;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog($level)) {
            $this->logger->log($level, $message, $context);
        }
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_EMERGENCY)) {
            $this->logger->emergency('[!!!!!] '.$message, $context);
        }
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_ALERT)) {
            $this->logger->alert('[!!] '.$message, $context);
        }
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_CRITICAL)) {
            $this->logger->critical('[!!!!] '.$message, $context);
        }
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_ERROR)) {
            $this->logger->error('[!!!] '.$message, $context);
        }
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_WARNING)) {
            $this->logger->warning('[!] '.$message, $context);
        }
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_NOTICE)) {
            $this->logger->notice($message, $context);
        }
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_INFO)) {
            $this->logger->info($message, $context);
        }
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog(self::LEVEL_DEBUG)) {
            $this->logger->debug('[DBG] '.$message, $context);
        }
    }
}
