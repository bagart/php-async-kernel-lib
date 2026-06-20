<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Job\JobState;

describe('JobState', function () {
    describe('isTerminal', function () {
        it('returns true for completed', function () {
            expect(JobState::COMPLETED->isTerminal())->toBeTrue();
        });

        it('returns true for failed', function () {
            expect(JobState::FAILED->isTerminal())->toBeTrue();
        });

        it('returns true for dead_letter', function () {
            expect(JobState::DEAD_LETTER->isTerminal())->toBeTrue();
        });

        it('returns false for new', function () {
            expect(JobState::NEW->isTerminal())->toBeFalse();
        });

        it('returns false for running', function () {
            expect(JobState::RUNNING->isTerminal())->toBeFalse();
        });

        it('returns false for retry', function () {
            expect(JobState::RETRY->isTerminal())->toBeFalse();
        });
    });

    describe('isRetryable', function () {
        it('returns true for running', function () {
            expect(JobState::RUNNING->isRetryable())->toBeTrue();
        });

        it('returns true for retry', function () {
            expect(JobState::RETRY->isRetryable())->toBeTrue();
        });

        it('returns false for completed', function () {
            expect(JobState::COMPLETED->isRetryable())->toBeFalse();
        });

        it('returns false for failed', function () {
            expect(JobState::FAILED->isRetryable())->toBeFalse();
        });

        it('returns false for dead_letter', function () {
            expect(JobState::DEAD_LETTER->isRetryable())->toBeFalse();
        });

        it('returns false for new', function () {
            expect(JobState::NEW->isRetryable())->toBeFalse();
        });
    });
});
