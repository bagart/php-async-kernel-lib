<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\Queue\ActivePartitionsContract;
use BAGArt\AsyncKernel\Contracts\Queue\ASKQueueAdapterContract;
use BAGArt\AsyncKernel\Contracts\Queue\PartitionStreamContract;
use BAGArt\AsyncKernel\Contracts\Queue\PendingAckRegistryContract;
use BAGArt\AsyncKernel\Contracts\Queue\RetryQueueSizeContract;
use BAGArt\AsyncKernel\ProcessingMetrics;
use BAGArt\AsyncKernel\Wrappers\ASKQueueWrapper;

describe('kernel-local queue contracts (C1/C2)', function () {
    it('loads the wrapper, the metrics and the contracts without any external package', function () {
        expect(class_exists(ASKQueueWrapper::class))->toBeTrue();
        expect(class_exists(ProcessingMetrics::class))->toBeTrue();

        expect(interface_exists(ASKQueueAdapterContract::class))->toBeTrue();
        expect(interface_exists(RetryQueueSizeContract::class))->toBeTrue();
        expect(interface_exists(ActivePartitionsContract::class))->toBeTrue();
        expect(interface_exists(PendingAckRegistryContract::class))->toBeTrue();
        expect(interface_exists(PartitionStreamContract::class))->toBeTrue();
    });

    it('type-hints only kernel-local contracts on the constructors', function (string $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();

        expect($constructor)->not->toBeNull();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            expect($type)->toBeInstanceOf(ReflectionNamedType::class);
            expect($type->isBuiltin())->toBeFalse();
            expect($type->getName())->toStartWith('BAGArt\\AsyncKernel\\');
        }
    })->with([ASKQueueWrapper::class, ProcessingMetrics::class]);

    it('declares only the methods the kernel actually calls', function (string $interface, array $expected) {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($interface))->getMethods(),
        );
        sort($methods);
        sort($expected);

        expect($methods)->toBe($expected);
    })->with([
        [ASKQueueAdapterContract::class, ['push', 'pop', 'size']],
        [RetryQueueSizeContract::class, ['retryQueueSize']],
        [ActivePartitionsContract::class, ['count']],
        [PendingAckRegistryContract::class, ['getPartitions', 'getPending']],
        [PartitionStreamContract::class, ['length']],
    ]);

    it('keeps the sources free of the external AskQueue / ASKClient namespaces', function () {
        $root = dirname(__DIR__, 2);

        $files = [
            $root.'/src/ProcessingMetrics.php',
            $root.'/src/Wrappers/ASKQueueWrapper.php',
        ];

        foreach ($files as $file) {
            expect(is_file($file))->toBeTrue();

            $contents = file_get_contents($file);

            expect($contents)->not->toContain('BAGArt\\AskQueue');
            expect($contents)->not->toContain('BAGArt\\ASKClient');
        }
    });

    it('lets one adapter implement both queue contracts without a signature collision', function () {
        $adapter = new class implements ASKQueueAdapterContract, RetryQueueSizeContract
        {
            public function push(string $queueName, string $payload): void
            {
            }

            public function pop(string $queueName): ?string
            {
                return null;
            }

            public function size(string $queueName): int
            {
                return 0;
            }

            public function retryQueueSize(): int
            {
                return 0;
            }
        };

        expect($adapter)->toBeInstanceOf(ASKQueueAdapterContract::class)
            ->and($adapter)->toBeInstanceOf(RetryQueueSizeContract::class);
    });
});
