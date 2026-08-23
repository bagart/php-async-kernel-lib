<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\ASKSignalHandlerContract;

final class SignalTriggers
{
    private static bool $shutdownRequested = false;
    private static bool $forceRequested = false;

    /**
     * @param  int[]  $signals  Signal constants to register handlers for
     * @param  int[]  $immediateForceSignals  Signals that trigger force shutdown immediately
     */
    public static function register(
        array $signals = [SIGINT, SIGTERM],
        ?ASKSignalHandlerContract $handler = null,
        array $immediateForceSignals = [SIGUSR1, SIGQUIT],
    ): void {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler ??= new ASKNullSignalHandler();

        pcntl_async_signals(true);

        // Immediate-force signals must be handled too, otherwise they keep
        // their default disposition (usually process termination).
        foreach (array_values(array_unique([...$signals, ...$immediateForceSignals])) as $signal) {
            pcntl_signal(
                $signal,
                static function () use ($signal, $handler, $immediateForceSignals): void {
                    if (self::$shutdownRequested || in_array($signal, $immediateForceSignals, true)) {
                        self::$forceRequested = true;
                        $handler->force($signal);

                        return;
                    }

                    self::$shutdownRequested = true;
                    $handler->graceful($signal);
                }
            );
        }
    }

    public static function isShutdownRequested(): bool
    {
        return self::$shutdownRequested;
    }

    public static function isForceRequested(): bool
    {
        return self::$forceRequested;
    }

    public static function reset(): void
    {
        self::$shutdownRequested = false;
        self::$forceRequested = false;
    }
}
