# php-async-kernel-lib — Docs Index

> Package: `bagart/async-kernel` (BAGArt\AsyncKernel). Homegrown Fiber-based cooperative scheduler. Verified 2026-09-17 against src/ (216 files across the 3 ASK packages).

## Read order

| Need | File |
|---|---|
| Kernel model, lifecycle, contracts | `sdd/async-kernel.md` |
| Review backlog closure (C1–C2, M1–M9, L1–L5), status matrix | `sdd/01-remaining-kernel-issues.md` |

## Source map (src/)

| Dir/File | Owns |
|---|---|
| `AsyncKernel.php`, `ASK.php` | kernel entry: addDaemon(), tick loop, shutdown phases |
| `Daemons/`, `Contracts/` | ASKDaemonContract, ASKTickableContract, WithASKTickableContract, ASKWarmableContract, ASKShutdownAware |
| `Contracts/Queue/` | kernel-local queue contracts (adapter, gauges) — the kernel never depends on client/queue packages |
| `Backpressure/` | pressure()/systemPressure signals |
| `Lockers/`, `Cache/`, `Timer/`, `Promise/` | kernel primitives |
| `Job/`, `Partition/`, `Drivers/` | job execution, partitioning, drivers |
| `Enum/ShutdownPhase` | RUNNING → STOPPING → DRAINING → FORCING → STOPPED |
| `Exceptions/` | ASKInterruptException (always bubbles; never catch in middleware) |
