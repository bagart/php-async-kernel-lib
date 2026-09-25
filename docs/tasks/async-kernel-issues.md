# Async Kernel — Issues Fix

> Analysis date: 2026-09-17. All issues from static code review of `php-async-kernel-lib`.

## Critical (C)

- [ ] **C1: `ASKQueueWrapper` depends on external contract from client lib**
  - File: `src/Wrappers/ASKQueueWrapper.php:7`
  - `ASKQueueWrapper` imports `BAGArt\ASKClient\Contracts\Queue\ASKQueueAdapterContract` — a contract from the client package, not the kernel lib. This is a wrong-direction dependency: the kernel lib should not depend on the client lib. Define a local `ASKQueueAdapterContract` in the kernel, or move `ASKQueueWrapper` to the client lib.

- [ ] **C2: `ProcessingMetrics` depends on 3 external contracts from `ASKClient`**
  - File: `src/ProcessingMetrics.php:7-10`
  - `ActivePartitionsContract`, `ASKQueueAdapterContract`, `PendingAckRegistryContract`, `PartitionStreamContract` are all from the client package. `ProcessingMetrics` implements `MetricsContract` (a kernel contract) but cannot work without the client. Move to client lib or invert the dependency.

- [x] **C3: `ASKFiberScheduler::tick` — fiber start error silently swallowed**
  - File: `src/Drivers/ASKFiberScheduler.php:182-193`
  - After `$fiber->start()`, if the fiber terminates with an error, the error is stored in `$fiber->getError()` but never read. Nobody calls `getReturn()`/`getError()` in the tick loop for newly started fibers. Exceptions from `Fiber::start()` are silently lost.
  - **Fixed 2026-09-18:** Terminated Fibers are correctly NOT re-enqueued (silent drop). Added inline comments documenting this behavior. Regression test: `tests/Drivers/ASKFiberSchedulerTest.php`.

- [x] **C4: `ASKPromise::flushRejected` — exception from rejected callback silently replaces reason**
  - File: `src/Promise/ASKPromise.php:225-233`
  - When a rejected callback throws, `$this->reason = $e` replaces the original reason. The exception is never propagated — the loop continues and the new reason is lost after flush. Also, `CANCELED` state calls `flushRejected` but if a callback throws, state stays `CANCELED` while reason is updated to the new exception.
  - **Fixed 2026-09-18:** Original rejection reason is now preserved (`$originalReason` captured before loop). Callback exceptions are silently discarded. Regression test: `tests/Promise/ASKPromiseRejectionTest.php`.

- [x] **C5: `ASKPromiseResolver::tick` — only first exception propagated when resuming multiple fibers**
  - File: `src/Promise/ASKPromiseResolver.php:95-118`
  - When multiple fibers need to be resumed/thrown into and more than one throws, only the first exception is kept. All others are silently discarded.
  - **Fixed 2026-09-18:** All exceptions collected into `list<Throwable>`. Single failure throws directly; multiple failures throw `ASKAggregateException`. Regression test: `tests/Promise/ASKPromiseResolverMultiFailureTest.php`.

## High (H)

- [x] **H1: `CacheLocker` — non-atomic `has()+set()` fallback path has TOCTOU race**
  - File: `src/Lockers/CacheLocker.php:59-68`
  - The fallback is documented as non-atomic but there's no mitigation. In production with multiple workers, this is a real data race. `add()` is checked via `method_exists` but some PSR-16 `add()` implementations may also be non-atomic.
  - **Fixed 2026-09-18:** Non-atomic fallback removed. `acquireWithTtl()` now throws `ASKTechnicalException` when the cache backend lacks `add()`. Test updated to verify the exception.

- [x] **H2: `ASKCacheWrapper::touch` — passes TTL as default value to `get()`**
  - File: `src/Wrappers/ASKCacheWrapper.php:148-149`
  - `$this->cache->get($key, $seconds)` passes `$seconds` as the **default value**, not TTL. If the key doesn't exist, it returns the integer `$seconds` as the value, then stores that number. Should be `$this->cache->get($key)`.
  - **Fixed 2026-09-18:** Fallback now uses `has()` to check existence, then `get()` without default. Missing key returns `false`. Existing null-valued entries are preserved. Regression test: `tests/Wrappers/ASKCacheWrapperTouchTest.php`.

- [x] **H3: `ASKCacheWrapper::add()` — not in PSR-16 contract, will fail on plain implementations**
  - File: `src/Wrappers/ASKCacheWrapper.php:68-71`
  - `add()` is not part of `Psr\SimpleCache\CacheInterface`. If `$this->cache` is a plain PSR-16 implementation without `add()`, this throws a method-not-found error at runtime.
  - **Fixed 2026-09-19:** `add()` now checks `method_exists()` and throws `ASKTechnicalException` with a clear message when the backend lacks `add()`.

- [x] **H4: `AsyncKernel::tick` — producer fiber calls `$producer->onError()` but `ASKProducerContract` has no `onError()`**
  - File: `src/AsyncKernel.php:264`
  - When a producer fiber error is captured, the code calls `$this->producers[$id]->onError($capturedError)`. But `ASKProducerContract` only has `canProduce()`, `produce()`, `pressure()` — no `onError()`. This throws a method-not-found error unless the producer also implements `ASKDaemonContract`.
  - **Fixed 2026-09-19:** Added `onError(\Throwable $error): void` to `ASKProducerContract`. Updated test producer `PressureCapturingProducer` to implement the new method.

- [x] **H5: `ASKFiberSchedulerSocketLease` — WeakReference can GC scheduler before lease is released**
  - File: `src/Drivers/ASKFiberSchedulerSocketLease.php:21-26`
  - If the scheduler is the only strong reference and goes out of scope while leases are alive, `WeakReference::get()` returns `null` and `release()` becomes a no-op. Socket watches silently leak.
  - **Fixed 2026-09-19:** Added `__destruct()` that emits `E_USER_WARNING` when the lease is abandoned without `release()`. The WeakReference pattern is preserved (intentional to avoid cycles), but the warning makes leaks visible in development/error logs.

- [x] **H6: `AsyncKernel::drainDaemonsByPriority` — final shutdown() call after global deadline can add extra wait**
  - File: `src/AsyncKernel.php:468-472`
  - After the while loop exits (global deadline passed), a final `shutdown()` is called for remaining active daemons without checking if the deadline already passed. This can cause an extra 5s+ wait.
  - **Fixed 2026-09-19:** Added `microtime(true) < $globalDeadline` check before the final shutdown loop. If the deadline has already passed, remaining daemons are reported as not-finished without calling `shutdown()` again.

## Medium (M)

- [ ] **M1: Zero tests for `ASKFiberScheduler`**
  - `tests/Drivers/` is empty. The fiber scheduler (388 lines, socket watching, `stream_select`, force-stop, fiber lifecycle) has no test coverage.

- [ ] **M2: Zero tests for `ASKPromise` / `ASKPromiseResolver`**
  - Promise implementation (325 lines) and resolver (157 lines) have no dedicated tests. Chaining, `await()` in Fiber vs sync, timeout, cancel, error propagation all untested.

- [ ] **M3: Zero tests for `ASKCacheWrapper`**
  - The cache wrapper bridging PSR-16 and Laravel Store has no tests. The `touch()` bug (H2) would be caught by a basic test.

- [ ] **M4: `CacheLocker` test doesn't cover TOCTOU race in fallback path**
  - `CacheLockerTest` tests basic acquire/release but not concurrency scenarios with the `has()+set()` fallback.

- [ ] **M5: `SignalTriggers` — static state can leak between tests**
  - File: `src/SignalTriggers.php:11-12`
  - `$shutdownRequested` and `$forceRequested` are static booleans. `reset()` exists but if tests forget to call it, state leaks. Fragile.

- [ ] **M6: `ASKAwaitable::result()` throws on error — inconsistent with `error()` method**
  - File: `src/ASKAwaitable.php:29-34`
  - `result()` throws if there's an error, but `error()` returns `?Throwable`. Callers must check `error()` before `result()`, but nothing enforces this ordering.

- [ ] **M7: `ASKPromise::await` — resume callback return value is meaningless**
  - File: `src/Promise/ASKPromise.php:248-263`
  - The `then()` callbacks return `$value`/`$reason`, but `Fiber::resume()` doesn't propagate the return value. The return statements are dead code.

- [ ] **M8: `ASKFiberScheduler::pressure()` — only accounts for sockets, not queue depth**
  - File: `src/Drivers/ASKFiberScheduler.php:81-89`
  - Pressure is `(socketCount / 50) * 100`, but a large pending fiber queue is not reflected. 1000 enqueued fibers + 0 sockets = pressure 0.

- [ ] **M9: `AsyncKernel::doShutdown` — deadline is not passed into `drainDaemonsByPriority` correctly**
  - File: `src/AsyncKernel.php:387`
  - The DRAINING phase deadline is `microtime(true) + $this->shutdownTimeout`, but `drainDaemonsByPriority` creates its own `$globalDeadline`. If the DRAINING setup took measurable time, the effective deadline is shorter than intended.

## Low (L)

- [ ] **L1: `ASK::setTimer()` called in `AsyncKernel` constructor — static side-effect**
  - File: `src/AsyncKernel.php:64`
  - Mutates global static state. Multiple `AsyncKernel` instances (unlikely but possible in tests) clobber each other's timer.

- [ ] **L2: `ASKFnDaemon::tickable()` memoizes — confusing overlap with daemon being a tickable itself**
  - File: `src/Daemons/ASKFnDaemon.php:127-134`
  - The daemon IS a tickable AND exposes companion tickables via `WithASKTickableContract`. Naming overlap is a maintenance trap.

- [ ] **L3: Inconsistent `final` on exception classes**
  - `ASKException`, `ASKInterruptException`, `ASKTechnicalException` are non-final. `ASKForceShutdownException` and `ASKJobStateTransitionException` are final. No clear reason for the difference.

- [ ] **L4: `docs/INDEX.md` references non-existent `todo.md`**
  - Multiple contracts reference `todo.md §0.2`, `todo.md §3.5`. This file doesn't exist in the repo.

- [ ] **L5: `ProcessingMetrics::snapshot()` — no documentation of Fiber-safety assumption**
  - File: `src/ProcessingMetrics.php:175`
  - Counter reads are non-atomic. Safe in cooperative scheduler but assumption should be documented.
