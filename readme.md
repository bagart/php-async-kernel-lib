# Async Kernel

A lightweight async runtime for PHP 8.1+ built on Fibers and Promises.

## Overview

Async Kernel provides a single-threaded event loop that coordinates concurrent tasks using PHP Fibers, Guzzle promises,
and a cooperative scheduling model. It is designed for long-running daemons that need to perform non-blocking I/O (HTTP
requests, polling, scheduled tasks) without external dependencies like ReactPHP or Amp.

## Architecture

```
AsyncKernel
├── Drivers (AsyncTickableContract)    — drive async I/O (Guzzle queue, curl_multi)
├── Daemons (DaemonContract)           — long-running tasks polled each tick
│   └── FiberScheduler (SchedulerContract) — Fiber-based cooperative scheduler
└── Promises (PromiseContract)         — resolve/reject with then/otherwise chains
```

### Core Components

| Class                     | Purpose                                                                |
|---------------------------|------------------------------------------------------------------------|
| `AsyncKernel`             | Main event loop — ticks drivers then daemons, manages idle/busy sleep  |
| `ASKPromise` / `ASKDeferred` | Promise/A+ style primitives with `then`, `otherwise`, `wait`, `cancel` |
| `ASKPromiseResolver`         | Awaits a promise from within a Fiber or blocking context               |
| `ASKFiberScheduler` | Enqueues and schedules Fibers with `sleep(delay)` support              |
| `GuzzlePromiseAdapter`    | Wraps `GuzzleHttp\Promise\PromiseInterface` into `ASKPromiseContract`     |
| `GuzzleTickableDriver`    | Ticks `GuzzleHttp\Promise\Utils::queue()` each cycle                   |
| `AskCurlMultiClientAdapter`         | Ticks a `CurlMultiHandler` each cycle                                  |

## Requirements

- PHP 8.1+
- `guzzlehttp/guzzle` (for Guzzle drivers/adapters)

## Quick Start

```php
use BAGArt\AsyncKernel\AsyncKernel;use BAGArt\AsyncKernel\Network\Drivers\GuzzleTickableDriver;use BAGArt\AsyncKernel\Promise\GuzzlePromiseAdapter;use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;use GuzzleHttp\Client;use Monolog\Handler\StreamHandler;use Monolog\Logger;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));

$kernel = new AsyncKernel($logger);
$kernel->addTickable(new GuzzleTickableDriver());

$client = new Client(['timeout' => 10]);
$resolver = new ASKPromiseResolver($kernel);

$promise = GuzzlePromiseAdapter::wrap(
    $client->requestAsync('GET', 'https://example.com/api')
);

$result = $resolver->await($promise);
```

## Creating a Daemon

Implement `ASKDaemonContract` to define a long-running task:

```php
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;

final class MyDaemon implements ASKDaemonContract
{
    private ?ASKPromiseContract $pending = null;
    private int $nextRunAfter = 0;

    public function startup(): void
    {
    }

    public function onError(\Throwable $e): void
    {
    }

    public function name(): string
    {
        return substr(strrchr(static::class, '\\'), 1);
    }

    public function tick(): void
    {
        if ($this->pending !== null) {
            // process previous result
            if ($this->pending->getState() === ASKPromiseContract::FULFILLED) {
                $data = $this->pending->getValue();
                // ...
            }
            $this->pending = null;
            $this->nextRunAfter = time() + 30;
            return;
        }

        if (time() < $this->nextRunAfter) {
            return;
        }

        // start new async work
        $this->pending = $this->fetchData();
    }

    public function shutdown(): bool
    {
        return $this->pending === null;
    }

    public function isIdle(): bool
    {
        return $this->pending === null;
    }

    public function queueSize(): int
    {
        return $this->pending !== null ? 1 : 0;
    }
}
```

Register it with the kernel:

```php
$kernel->addDaemon(new MyDaemon());
$kernel->run();
```

## Fiber Scheduler

Use `ASKFiberScheduler` to run concurrent Fiber-based tasks:

```php
use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;

$scheduler = new ASKFiberScheduler();

$scheduler->enqueue(function (): void {
    echo "Task 1 started\n";
    Fiber::suspend(); // yield
    echo "Task 1 resumed\n";
});

$scheduler->enqueue(function (): void {
    echo "Task 2 started\n";
});

$kernel->addDaemon($scheduler);
$kernel->run();
```

### Delayed Execution

```php
$scheduler->sleep(function (): void {
    echo "Delayed task\n";
}, seconds: 5);
```

## Promise States

```
PENDING  →  FULFILLED (resolve)
       ↘  REJECTED  (reject)
       ↘  cancelled (cancel)
```

- `then($onFulfilled, $onRejected)` — chain handlers
- `otherwise($onRejected)` — catch rejection
- `wait($unwrap)` — block until settled (requires a wait function or strategy)
- `cancel()` — mark as cancelled

## PromiseResolver

`PromiseResolver::await($promise, $timeout)` works in two modes:

1. **Inside a Fiber** — suspends the Fiber, resumes on resolve/reject
2. **Outside a Fiber** — spins `kernel->tick()` until settled

## Configuration

```php
use BAGArt\AsyncKernel\ASKClock;use BAGArt\AsyncKernel\Enum\ExceptionPolicy;use BAGArt\AsyncKernel\KernelSleepStrategy\AdaptiveKernelSleepStrategy;

$kernel = new AsyncKernel(
    logger: $kernelLogger,
    clock: new ASKClock(),
    sleepStrategy: new AdaptiveKernelSleepStrategy(
        idleMicroseconds: 1_000,   // sleep when queue empty
        busyMicroseconds: 0,       // sleep between busy ticks
    ),
    exceptionPolicy: ExceptionPolicy::INTERRUPT, // throw on daemon/driver exceptions
);
```

### Sleep Strategies

| Strategy                          | Description                                                                  |
|-----------------------------------|------------------------------------------------------------------------------|
| `AdaptiveKernelSleepStrategy`           | Linearly increases idle sleep up to 1ms; configurable idle/busy microseconds |
| `ExponentialBackoffSleepStrategy` | Doubles idle sleep each tick (capped)                                        |
| `NoSleepStrategy`                 | No sleep between ticks (busy-wait)                                           |

### Exception Policies

| Policy                            | Behavior                                  |
|-----------------------------------|-------------------------------------------|
| `ExceptionPolicy::IGNORE`         | Log and continue                          |
| `ExceptionPolicy::STOP_KERNEL`    | Gracefully stop the kernel                |
| `ExceptionPolicy::RESTART_DAEMON` | Log and continue (future: restart daemon) |
| `ExceptionPolicy::INTERRUPT`      | Rethrow the exception (default)           |

## Graceful Shutdown

`$kernel->shutdown()` calls `shutdown()` on each daemon in a loop until all return `true`. Daemons should flush pending
work and return `true` when fully stopped.

`$kernel->stop($reason)` requests a stop after the current tick completes.

## Examples

Run the example daemon (fetches USD→EUR rate every 10s):

```bash
php commands/example-daemon.php
php commands/example-daemon.php --interval=5
```

## Directory Structure

```
src/
├── Contracts/
│   ├── AsyncKernelContract.php      — tick/run interface
│   ├── AsyncTickableContract.php    — tick/isIdle/queueSize
│   ├── DaemonContract.php           — daemon with shutdown
│   ├── PromiseContract.php          — promise states & chaining
│   └── SchedulerContract.php        — fiber scheduler interface
├── Exceptions/
│   └── AsyncKernelException.php
├── Runtime/
│   ├── ClientParts/
│   │   ├── AsyncSocketKernel.php
│   │   ├── CurlMultiDriver.php
│   │   ├── GuzzleDriver.php
│   │   ├── GuzzlePromiseAdapter.php
│   │   └── OutboundRequest.php
│   ├── AsyncKernel.php
│   ├── Deferred.php
│   ├── FiberScheduler.php
│   ├── Promise.php
│   ├── PromiseFactory.php
│   └── PromiseResolver.php
```
