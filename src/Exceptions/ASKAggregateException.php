<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Exceptions;

/**
 * Collects multiple independent exceptions from a single operation
 * (e.g. several Fibers failing during one resolver tick).
 *
 * Preserves deterministic ordering and provides direct access to
 * all failure reasons without requiring a linear Throwable::$previous chain.
 */
final class ASKAggregateException extends ASKException
{
    /** @var list<\Throwable> */
    private readonly array $exceptions;

    /**
     * @param  list<\Throwable>  $exceptions  Non-empty list of caught exceptions.
     * @param  string  $message
     */
    public function __construct(array $exceptions, string $message = '')
    {
        assert($exceptions !== []);

        $this->exceptions = $exceptions;

        parent::__construct(
            $message ?: '['.count($exceptions).'] concurrent failures: '.$exceptions[0]->getMessage(),
            0,
            $exceptions[0],
        );
    }

    /**
     * All captured exceptions in deterministic order (first failure first).
     *
     * @return list<\Throwable>
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }
}
