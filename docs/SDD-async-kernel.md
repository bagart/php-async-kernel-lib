# Async Kernel — SDD

> `bagart/async-kernel` — Fiber scheduler library. No external service connections in constructors (lazy via `warm()`).

## Identity

Cooperative multitasking for long-running PHP daemons: fibers, tickables, 3-phase graceful shutdown. Base of every platform daemon (outbound, poller, module cron workers).

## Model (verified 2026-09-17)

- **Tick loop:** kernel iterates daemons; `tick(int $systemPressure)` on `ASKTickableContract`; `WithASKTickableContract::tickable()` returns `[tickables, scheduler]` — scheduler is mandatory.
- **Warmup:** `AsyncKernel::addDaemon()` calls `warm()` when `ASKWarmableContract` — the designated lazy-connect hook.
- **Shutdown:** `RUNNING → STOPPING (prepareShutdown, no new tasks) → DRAINING (by shutdownPriority, higher first) → FORCING (timeout elapsed → force cut) → STOPPED`. `shutdown()` returning false keeps draining; in-flight work finishes even if it takes minutes.
- **Interrupt:** `ASKInterruptException` bubbles through everything; pipeline/middleware catch business exceptions only.
- **Backpressure:** `pressure()`/`systemPressure` passed into tick — producers slow down under load.

## Rules for daemons

1. Constructor does zero I/O. 2. Implement tickable+warmable at minimum; `ASKShutdownAware` for drain semantics. 3. State in Redis = readonly DTOs only. 4. Never register a daemon as container singleton — build in CLI command.
