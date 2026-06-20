<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable;
use BAGArt\AsyncKernel\Timer\ASKTimer;

final class ASK
{
    private static ?ASKTimer $timer = null;

    public static function setTimer(ASKTimer $timer): void
    {
        self::$timer = $timer;
    }

    public static function sleep(int $milliseconds): ASKSleepAwaitable
    {
        if (self::$timer === null) {
            throw new ASKTechnicalException(
                'ASK::sleep() requires an ASKTimer. '
                .'AsyncKernel registers one by default; '
                .'in tests call ASK::setTimer(new ASKTimer()) first.'
            );
        }

        return self::$timer->sleep($milliseconds);
    }
}
