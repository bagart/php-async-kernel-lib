<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKAggregateException;
use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKForceShutdownException;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Exceptions\ASKJobStateTransitionException;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;

describe('Exception finality rule (L3)', function () {
    it('keeps the base ASKException non-final and extensible', function () {
        $reflection = new ReflectionClass(ASKException::class);

        expect($reflection->isFinal())->toBeFalse();
        expect($reflection->isAbstract())->toBeFalse();
        expect($reflection->getParentClass()?->getName())->toBe(RuntimeException::class);
    });

    it('declares every concrete exception in src/Exceptions final', function () {
        $files = glob(__DIR__ . '/../../src/Exceptions/*.php');

        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            if (basename($file) === 'ASKException.php') {
                continue;
            }

            expect(basename($file))->toStartWith('ASK');
            expect(file_get_contents($file))
                ->toMatch('/^final class /m')
                ->toContain('extends ASKException');
        }
    });

    it('marks every direct subclass of ASKException final', function () {
        $subclasses = [
            ASKAggregateException::class,
            ASKForceShutdownException::class,
            ASKInterruptException::class,
            ASKJobStateTransitionException::class,
            ASKTechnicalException::class,
        ];

        foreach ($subclasses as $subclass) {
            $reflection = new ReflectionClass($subclass);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->getParentClass()?->getName())->toBe(ASKException::class);
        }
    });
});
