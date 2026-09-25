# Async Kernel Remaining Issues — H3–H6, M1–M9, L1–L5

## Status

`[x]` H3–H6 DONE (2026-09-19). M1–M9, L1–L5 remain.

## Problem

After Phase 1 fixes (C3–C5, H1–H2), the async kernel has 14 remaining issues documented in `misc/BAGArt/php-async-kernel-lib/docs/tasks/async-kernel-issues.md`:

### High (H3–H6)
- **H3**: `ASKCacheWrapper::add()` not in PSR-16 — contract gap, no atomic add-if-not-exists
- **H4**: `AsyncKernel::tick()` producer `onError` — errors in producer tick callbacks are silently swallowed
- **H5**: `ASKFiberSchedulerSocketLease` — `WeakReference` can GC scheduler before lease is released, socket watches leak
- **H6**: `AsyncKernel::drainDaemonsByPriority` — final `shutdown()` call after global deadline adds extra 5s+ wait

### Medium (M1–M9)
- **M1**: Zero tests for `ASKFiberScheduler` (Phase 1 added basic tests, but coverage is minimal)
- **M2**: Zero tests for `ASKPromise`/`ASKPromiseResolver` (Phase 1 added regression tests, not comprehensive)
- **M3**: Zero tests for `ASKCacheWrapper` (Phase 1 added `touch()` tests, not full coverage)
- **M4**: `CacheLocker` test doesn't cover TOCTOU race in fallback path
- **M5**: `SignalTriggers` — static state can leak between tests
- **M6**: `ASKAwaitable::result()` throws on error — inconsistent with `error()` method
- **M7**: `ASKPromise::await` — resume callback return value is dead code
- **M8**: `ASKFiberScheduler::pressure()` — only accounts for sockets, not queue depth
- **M9**: `AsyncKernel::doShutdown` — deadline not passed into `drainDaemonsByPriority` correctly

### Low (L1–L5)
- **L1**: `ASK::setTimer()` called in `AsyncKernel` constructor — static side-effect
- **L2**: `ASKFnDaemon::tickable()` memoizes — confusing overlap with daemon being a tickable itself
- **L3**: Inconsistent `final` on exception classes
- **L4**: `docs/INDEX.md` references non-existent `todo.md`
- **L5**: `ProcessingMetrics::snapshot()` — no documentation of Fiber-safety assumption

## Contract

Each item is a separate mini-task. Prioritize H3–H6 (correctness), then M1–M9 (coverage), then L1–L5 (cleanup).

### Acceptance Criteria
```text
[ ] H3: add() contract decision made (add to PSR-16 or document as extension)
[ ] H4: producer onError is called on tick failure
[ ] H5: WeakReference lifecycle is safe (test proves no leak)
[ ] H6: drainDaemonsByPriority respects global deadline
[ ] M1-M3: test coverage improved
[ ] M4: TOCTOU race test added
[ ] M5-M9: issues documented or fixed
[ ] L1-L5: cleanup or documented as intentional
```
