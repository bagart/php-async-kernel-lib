<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use Closure;

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
        ?Closure $onGraceful = null,
        ?Closure $onForce = null,
        array $immediateForceSignals = [SIGUSR1, SIGQUIT],
    ): void {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ($signals as $signal) {
            pcntl_signal($signal, static function () use ($signal, $onGraceful, $onForce, $immediateForceSignals): void {
                if (self::$shutdownRequested || in_array($signal, $immediateForceSignals, true)) {
                    self::$forceRequested = true;
                    if ($onForce !== null) {
                        ($onForce)($signal);
                    }

                    return;
                }

                self::$shutdownRequested = true;
                if ($onGraceful !== null) {
                    ($onGraceful)($signal);
                }
            });
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
