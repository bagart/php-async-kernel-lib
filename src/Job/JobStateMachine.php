<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Job;

use BAGArt\AsyncKernel\Exceptions\ASKJobStateTransitionException;

final class JobStateMachine
{
    public static function canTransition(JobState $from, JobState $to): bool
    {
        return $from->canTransitionTo($to);
    }

    public static function transition(JobState $from, JobState $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new ASKJobStateTransitionException(
                "State transition {$from->value} → {$to->value} is not allowed",
            );
        }
    }

    public static function allowedTargets(?JobState $from): array
    {
        if ($from === null) {
            return [JobState::NEW];
        }

        return $from->allowedTransitions();
    }

    public static function isZombieRecoverable(JobState $state): bool
    {
        return $state === JobState::RUNNING;
    }
}
