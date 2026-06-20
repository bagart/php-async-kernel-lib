<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKJobStateTransitionException;
use BAGArt\AsyncKernel\Job\JobState;
use BAGArt\AsyncKernel\Job\JobStateMachine;

describe('JobStateMachine', function () {
    describe('canTransition', function () {
        it('allows new -> running', function () {
            expect(JobStateMachine::canTransition(JobState::NEW, JobState::RUNNING))->toBeTrue();
        });

        it('allows running -> completed', function () {
            expect(JobStateMachine::canTransition(JobState::RUNNING, JobState::COMPLETED))->toBeTrue();
        });

        it('allows running -> failed', function () {
            expect(JobStateMachine::canTransition(JobState::RUNNING, JobState::FAILED))->toBeTrue();
        });

        it('allows running -> retry', function () {
            expect(JobStateMachine::canTransition(JobState::RUNNING, JobState::RETRY))->toBeTrue();
        });

        it('allows retry -> running', function () {
            expect(JobStateMachine::canTransition(JobState::RETRY, JobState::RUNNING))->toBeTrue();
        });

        it('allows retry -> dead_letter', function () {
            expect(JobStateMachine::canTransition(JobState::RETRY, JobState::DEAD_LETTER))->toBeTrue();
        });

        it('allows failed -> dead_letter', function () {
            expect(JobStateMachine::canTransition(JobState::FAILED, JobState::DEAD_LETTER))->toBeTrue();
        });

        it('allows failed -> running (zombie recovery)', function () {
            expect(JobStateMachine::canTransition(JobState::FAILED, JobState::RUNNING))->toBeTrue();
        });

        it('prevents running -> running', function () {
            expect(JobStateMachine::canTransition(JobState::RUNNING, JobState::RUNNING))->toBeFalse();
        });

        it('prevents completed -> retry', function () {
            expect(JobStateMachine::canTransition(JobState::COMPLETED, JobState::RETRY))->toBeFalse();
        });

        it('prevents completed -> running', function () {
            expect(JobStateMachine::canTransition(JobState::COMPLETED, JobState::RUNNING))->toBeFalse();
        });

        it('prevents dead_letter -> any', function () {
            expect(JobStateMachine::canTransition(JobState::DEAD_LETTER, JobState::RUNNING))->toBeFalse();
            expect(JobStateMachine::canTransition(JobState::DEAD_LETTER, JobState::NEW))->toBeFalse();
            expect(JobStateMachine::canTransition(JobState::DEAD_LETTER, JobState::COMPLETED))->toBeFalse();
        });
    });

    describe('transition', function () {
        it('does not throw for allowed transition', function () {
            JobStateMachine::transition(JobState::NEW, JobState::RUNNING);
            expect(true)->toBeTrue();
        });

        it('throws for forbidden transition', function () {
            expect(fn () => JobStateMachine::transition(JobState::COMPLETED, JobState::RETRY))
                ->toThrow(ASKJobStateTransitionException::class);
        });
    });

    describe('allowedTargets', function () {
        it('returns only NEW for null state', function () {
            $targets = JobStateMachine::allowedTargets(null);
            expect($targets)->toBe([JobState::NEW]);
        });

        it('returns targets for running', function () {
            $targets = JobStateMachine::allowedTargets(JobState::RUNNING);
            expect($targets)->toBe([
                JobState::COMPLETED,
                JobState::FAILED,
                JobState::RETRY,
            ]);
        });

        it('returns empty for completed', function () {
            $targets = JobStateMachine::allowedTargets(JobState::COMPLETED);
            expect($targets)->toBe([]);
        });
    });

    describe('isZombieRecoverable', function () {
        it('returns true for running', function () {
            expect(JobStateMachine::isZombieRecoverable(JobState::RUNNING))->toBeTrue();
        });

        it('returns false for completed', function () {
            expect(JobStateMachine::isZombieRecoverable(JobState::COMPLETED))->toBeFalse();
        });

        it('returns false for new', function () {
            expect(JobStateMachine::isZombieRecoverable(JobState::NEW))->toBeFalse();
        });

        it('returns false for dead_letter', function () {
            expect(JobStateMachine::isZombieRecoverable(JobState::DEAD_LETTER))->toBeFalse();
        });
    });
});
