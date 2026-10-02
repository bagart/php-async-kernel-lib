# php-async-kernel-lib — Docs Index

> Package: `bagart/async-kernel` (BAGArt\AsyncKernel). Homegrown Fiber-based cooperative scheduler. Verified 2026-09-17 against src/ (216 files across the 3 ASK packages).

## Read order

| Need | File |
|---|---|
| Kernel model, lifecycle, contracts | `sdd/async-kernel.md` |

## Source map (src/)

| Dir/File | Owns |
|---|---|
| `AsyncKernel.php`, `ASK.php` | kernel entry: addDaemon(), tick loop, shutdown phases |
| `Daemons/`, `Contracts/` | ASKDaemonContract, ASKTickableContract, WithASKTickableContract, ASKWarmableContract, ASKShutdownAware |
| `Backpressure/` | pressure()/systemPressure signals |
| `Lockers/`, `Cache/`, `Timer/`, `Promise/` | kernel primitives |
| `Job/`, `Partition/`, `Drivers/` | job execution, partitioning, drivers |
| `Enum/ShutdownPhase` | RUNNING → STOPPING → DRAINING → FORCING → STOPPED |
| `Exceptions/` | ASKInterruptException (always bubbles; never catch in middleware) |
