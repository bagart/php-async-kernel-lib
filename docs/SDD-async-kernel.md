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

## Phase 1 Correctness Decisions (2026-09-18)

### Fiber Error Semantics (C3)

Terminated Fibers (threw inside) are silently dropped by the scheduler. They are NOT re-enqueued. Callers observe errors through Promise rejection or Fiber-level `getError()`. No special exception propagation layer needed.

### Promise Rejection Reason Preservation (C4)

The original rejection reason is immutable after rejection. Secondary callback exceptions in `flushRejected()` do NOT replace `$this->reason`. Callback failures are diagnostics only; the primary reason is preserved for all subsequent callbacks and `getReason()` callers.

### Multi-Failure Aggregation (C5)

`ASKPromiseResolver::tick()` now throws `ASKAggregateException` when multiple Fibers fail in one tick. Single failure still throws the original exception directly. Ordering is deterministic (first failure first). `ASKAggregateException::getExceptions()` returns the full list.

### CacheLocker Atomicity (H1)

`CacheLocker` requires an atomic set-if-not-exists primitive (`add()`) for distributed locking. The non-atomic `has()+set()` fallback has been removed — it now throws `ASKTechnicalException` when the cache backend lacks `add()`. Callers must use Redis, APCu, or similar backends.

### CacheWrapper touch() Semantics (H2)

`ASKCacheWrapper::touch()` fallback uses `has()` to distinguish missing keys from cached `null`. A missing key returns `false` without writing. An existing key (including `null`-valued) is re-set with the new TTL. The TTL integer is never written as the cache value.

## Phase 2 Coverage & Fixes (2026-09-20)

### Test Coverage Added

- **ASKPromise comprehensive** (40 tests): state transitions, chaining, cancel, idempotency, await in Fiber, wait() timeout.
- **InMemoryCache PSR-16** (34 tests): TTL, trait methods (put/increment/decrement/forever/touch/forget/flush/many), edge cases.
- **CacheLocker TOCTOU race** (4 tests): non-atomic add() rejection, mutual exclusion, release re-acquire.

### Code Fixes

- **M6: `ASKAwaitable::result()`** — now throws `$this->error` instead of returning it; consistent with `ASKPromise::result()` and the `error()` contract.
- **M7: `ASKPromise::await`** — removed dead `$value`/`$reason` parameters from resume callbacks; added docblock.
- **M9: `AsyncKernel::doShutdown`** — computes `$globalDeadline` fresh from `microtime(true) + drainTimeout` instead of using stale `$deadline` after `prepareAllDaemonsShutdown()`.
- **L4: `ASKLockerContract`** — removed references to non-existent `todo.md` from docblock.

### Already Handled (no code changes needed)

M1–M5, M8, L1–L3, L5 were already addressed in prior phases or documented as intentional. Full audit confirms no gaps.

## Daemon Restart Policy (RESTART_DAEMON)

When `exceptionPolicy === ExceptionPolicy::RESTART_DAEMON`, a failing daemon is captured (tickables + producers), `onError()` is called, and restart is queued via `$pendingDaemonRestarts`. After the tick loop completes, old registrations are removed, the daemon is re-warmed, and re-added. Non-daemon tickable errors have no `onError()` lifecycle — they re-throw out of `tick()` so `run()` applies `exceptionPolicy` (log + continue under `IGNORE`, propagate under `INTERRUPT`, stop under `STOP_KERNEL`; contract 06 §44–§46). Revised 2026-09-23 (Q8): log-only swallowing bypassed the policy and hung the kernel under the default `INTERRUPT` policy.

## Phase 3 Reliability Hardening (2026-09-20)

### Dead Code Removal

- **SLEEPING-MAP-DEAD-CODE**: Removed `$sleeping` array, `wakeSleepingFibers()`, and all references. Sleep is delegated to `ASKTimer` + `ASKSleepAwaitable` (separate tickables), not the scheduler. `pollSocketsWithTimeout()` simplified — always uses 1s poll for non-stopped mode.

### Documentation

- **SPLQUEUE-FRAGMENTATION**: Documented O(1) trade-off on `$queue` field.
- **ASKCLOCK-SLEEP-GRANULARITY**: Documented usleep() granularity limits; hrtime busy-wait loop handles final sub-ms chunk.

## Phase 4 Grilling Hardening (2026-09-20)

### Dead Parameter Removal

- **drainDaemonsByPriority**: Removed unused `$deadline` parameter. Method now computes its own `$globalDeadline` from `$this->drainTimeout`. Caller updated.

### Promise Correctness

- **flushFulfilled eager transition**: Added `$this->maybeTransition()` after the flush loop. Previously, FULFILLED→REJECTED transition (from callback errors) was lazy — only triggered on next state read. Now transitions immediately, preventing stale FULFILLED state.
- **fulfillHandler re-throw removed**: `ASKPromise::then()` fulfill handler no longer re-throws after rejecting the child. Child is already rejected; the throw just created noise. Matches `rejectHandler` pattern.
- **wait() rejection propagation**: `ASKPromiseResolver::wait()` now re-throws after `error_log`. Previously swallowed rejections silently, allowing fibers to continue past `await()` as if nothing happened.

### Scheduler Cleanup

- **tick() single increment**: `$processed++` moved to top of loop body. Previously incremented in two separate locations (catch branch + fall-through), relying on `continue` to prevent double-counting. Single location is safer and clearer.

### Daemon Restart Completeness

- **restartDaemon fiber cleanup**: Added `unset($this->producerFibers[$id], $this->producerFiberErrors[$id])` to `restartDaemon()`. Previously, restarting a daemon with an in-flight producer fiber leaked the fiber reference and retained stale error data.

## Phase 4 Resolution Verified (2026-09-23)

Grilling questions Q1–Q10 (`docs/questions/2026-09-20-diff-review.md`) resolved and verified against `src/`:

- **Q1, Q6, Q10**: no-change decisions confirmed (final-shutdown guard, `E_USER_WARNING`, pressure thresholds).
- **Q2, Q3, Q4, Q5, Q7, Q9**: Phase 4 code changes re-verified in source.
- **Q8 revised**: plain tickable failures re-throw into `exceptionPolicy` instead of log-only (refutes the original "intentional" premise — broke contract test 06 §44–§46 and hung under default `INTERRUPT`). No `onError` on `ASKTickableContract` still holds.
- **Tests realigned with resolutions**: parent stays FULFILLED when `.then()` callbacks throw (Q4); `wait()` rejection propagates from `tick()` (Q9); C5 multi-failure tests suspend fibers on pending promises (`await()` short-circuits settled ones).
- **Latent bug fixed**: `ASKAggregateException::__construct` passed a `Throwable` as `$code` — corrected to the `previous` parameter (was unreachable until C5 tests actually aggregated).
- **Suite**: 242 passed, 0 failed (`vendor/bin/pest`).

## Known Limitations

- **C1/C2 open — AskQueue contract extraction not done**: `ASKQueueWrapper` and `ProcessingMetrics` still import `BAGArt\AskQueue\Contracts\*` — a namespace with no package/autoload behind it (fatal if those classes load; no tests cover them). `ASKCacheWrapper` no longer declares the non-loadable `AtomicCacheContract` marker; its `add()` still provides atomic semantics. Resolution path: extract shared queue/cache contracts to an `AskQueue` package (or move the wrappers to the client lib and invert the dependency) — tracked in `docs/tasks/async-kernel-issues.md` (C1, C2).
