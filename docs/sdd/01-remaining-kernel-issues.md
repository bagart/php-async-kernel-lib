# SDD: Remaining Kernel Issues — C1–C2, M1–M9, L1–L5

> Status: DONE
> Date: 2026-09-26
> Task: `docs/tasks/async-kernel-issues.md`, `docs/tasks/TASK-remaining-kernel-issues.md` (удалены)

## What Was Built

Закрыт весь остаток статического ревью асинхронного ядра (16 открытых пунктов).
Работа выполнена мультиагентно: 6 параллельных агентов по непересекающимся
файловым областям + центральная проверка.

Итог: внешние зависимости ядра устранены (C1/C2), контракты зафиксированы
документацией и пин-тестами (M6/M7/L1/L2/L5), дедлайн DRAINING обобщён (M9),
покрытие тестами расширено с 242 до 373 тестов.

## Files

### Source

- `src/Contracts/Queue/ASKQueueAdapterContract.php` — локальный контракт адаптера очереди ядра (`push`, `pop`, `size(string)`)
- `src/Contracts/Queue/ActivePartitionsContract.php` — гейдж активных партиций (`count()`)
- `src/Contracts/Queue/PendingAckRegistryContract.php` — реестр неподтверждённых записей
- `src/Contracts/Queue/PartitionStreamContract.php` — длина потока партиции (`length(string)`)
- `src/Contracts/Queue/RetryQueueSizeContract.php` — гейдж retry-очереди (`retryQueueSize()`)
- `src/Wrappers/ASKQueueWrapper.php` — переведён на локальный контракт, `@deprecated` сохранён
- `src/ProcessingMetrics.php` — переведён на локальные контракты + docblock Fiber-safety (L5)
- `src/AsyncKernel.php` — общий дедлайн DRAINING (M9), расширен комментарий конструктора (L1)
- `src/ASK.php` — docblock `setTimer()`: process-wide, last-wins (L1)
- `src/ASKAwaitable.php`, `src/Contracts/ASKAwaitableContract.php` — зафиксирован контракт `result()`/`error()`/`await()` (M6)
- `src/Daemons/ASKFnDaemon.php` — docblock `tickable()`: компаньон-tickables ≠ сам daemon, назначение мемоизации (L2)
- `composer.json` — починен скрипт `test` (флаг `--colors=always` не поддерживается Pest 4)

### Tests (новые)

- `tests/QueueContracts/` — `LocalQueueContractsTest`, `ASKQueueWrapperContractTest`, `ProcessingMetricsContractTest`
- `tests/Drivers/ASKFiberSchedulerSocketTest.php`, `tests/Drivers/ASKFiberSchedulerForceStopTest.php`, `tests/Drivers/ASKFiberSchedulerSocketLeaseTest.php` (+5 в `ASKFiberSchedulerCoverageTest`)
- `tests/Promise/ASKPromiseAwaitGapsTest.php`, `tests/Promise/ASKPromiseResolverAwaitCoverageTest.php`
- `tests/Awaitable/ASKAwaitableResultContractTest.php`
- `tests/Wrappers/ASKCacheWrapperTest.php` (+ `Fixtures/IlluminateStoreStub.php`)
- `tests/Lockers/CacheLockerNoAtomicFallbackTest.php`
- `tests/Exceptions/ExceptionFinalityTest.php`
- `tests/AsyncKernelShutdownDeadlineTest.php`, `tests/ASKFacadeTimerRegistrationTest.php`

## Architecture Decisions

1. **C1/C2 → локальные контракты ядра, а не перенос в клиент-библиотеку.**
   Направление зависимости верное: `ask-client` зависит от ядра, не наоборот.
   Перенос рассматривался, но отклонён — в этом workspace тесты `ask-client`
   незапускаемы (отсутствует path-repo `../telegram-platform-devops-baseline`),
   значит регрессию было нечем проверить. Контракты минимальные — только те
   методы, которые реально вызывает ядро.
2. **`RetryQueueSizeContract::retryQueueSize()` вместо общего `size()`.**
   Замечено при ревью: `ASKQueueAdapterContract::size(string $queue)` и гейдж
   `size()` имели бы разные сигнатуры при одном имени — класс, реализующий оба
   контракта, дал бы fatal «Can't inherit abstract function». Гейдж переименован,
   коллизия закрыта пин-тестом.
3. **M6 — контракт зафиксирован, поведение не менялось.** `result()` бросает
   сохранённую ошибку: это единственный канал проброса ошибки из `await()`
   (`ASKSleepAwaitable`), возврат `null` превратил бы reject в тихий успех.
   Альтернатива (не бросать) сломала бы `ASKPromise::result()`/`ASKDeferred::result()`
   и существующие тесты.
4. **M9 — параметр `drainDaemonsByPriority(float $globalDeadline)` возвращён.**
   Отменяет «Phase 4 Dead Parameter Removal»: тогда параметр удалили как
   неиспользуемый, но именно он нужен, чтобы значение, обещанное daemon'ам через
   `ASKShutdownContext::deadline()`, и бюджет цикла дрейна были одним числом.
   Бюджет — `drainTimeout` (30с), а не `shutdownTimeout` (5с): цикл дрейна этим
   бюджетом и управлял, расхождение 5с/30с и было дефектом.
5. **L1/L2 — «documented as intentional», не cleanup.** Убрать static-таймер
   фасада нельзя (публичный контракт `ASK::sleep()`), мемоизация `tickable()`
   безвредна (элементы readonly-стабильны). Оба решения задокументированы там,
   где живут, и закреплены тестами.
6. **H1/H3–H6, C3–C5, M5, M7, M8, L3, L4 — уже были исправлены** коммитом
   `d8f20c5` (2026-09-25), но не отмечены в task-файлах: закрыты как
   `already fixed` + пин-тесты, где покрытия не хватало.

## Status Matrix

| Item | Статус | След |
|------|--------|------|
| C1 `ASKQueueWrapper` external contract | fixed now | local contract |
| C2 `ProcessingMetrics` external contracts | fixed now | 4 local contracts |
| C3–C5 fiber/rejection/multi-failure | already fixed (d8f20c5) | — |
| H1–H6 cache/producer/lease/shutdown | already fixed (2026-09-19); H5 дополнительно закреплён `ASKFiberSchedulerSocketLeaseTest` (6 тестов) | lease test |
| M1 scheduler coverage | coverage extended (+23) | `tests/Drivers/` |
| M2 promise/resolver coverage | coverage extended (+26) | `tests/Promise/` |
| M3 cache wrapper coverage | coverage extended (+27) | `tests/Wrappers/` |
| M4 CacheLocker TOCTOU | coverage extended (+4) | `tests/Lockers/` |
| M5 SignalTriggers static state | already fixed | isolation tests |
| M6 `result()` inconsistency | documented + tested | contract docblock |
| M7 dead resume return | already fixed | 3 pin-tests |
| M8 `pressure()` queue depth | already fixed | 4 pin-tests |
| M9 DRAINING deadline | fixed now | shared deadline |
| L1 `ASK::setTimer()` side-effect | documented as intentional | 3 pin-tests |
| L2 `tickable()` memoization | documented | docblock |
| L3 `final` on exceptions | already fixed | reflection test |
| L4 `todo.md` references | already fixed | — |
| L5 `snapshot()` Fiber-safety | documented | docblock + tests |

## Tests

- `vendor/bin/pest` / `composer test` → **373 passed, 0 failed, 1 deprecated** (было 242)
- Deprecated — pre-existing `tests/ASKFacadeTest.php` `ReflectionProperty::setAccessible()`
- Дополнительно: `tests/QueueContracts/LocalQueueContractsTest` — контракты
  взаимно реализуемы одним классом (защита от коллизии сигнатур), в `src/`
  не осталось ссылок на `BAGArt\AskQueue` / `BAGArt\ASKClient`

## Integration Points

- `bagart/ask-client` — потребитель ядра; его `ASKClient\Contracts\Queue\*`
  не менялись. Локальные контракты ядра не конфликтуют по namespace.
- `parser-engine` — потребитель ядра; `ProcessingMetrics`/`ASKQueueWrapper`
  ими не используются (проверено grep'ом по workspace).
- Локальные контракты ядра — основа, которую позможно сделать родительскими для
  контрактов клиента (см. Known Limitations).

## Known Limitations

- Классы-обёртки `ASKQueueWrapper`/`ProcessingMetrics` не имеют потребителей в
  workspace и по-прежнему помечены `@deprecated`/внутренними; реальная
  интеграция с адаптером возможна только после того, как адаптер начнёт
  реализовывать контракты ядра (контракты клиента их не расширяют).
- Пакет `bagart/ask-queue`, упомянутый в `@deprecated`-докблоке и в
  `docs/SDD-ask-client.md`, в workspace отсутствует — выделение общих
  queue-контрактов в отдельный пакет остаётся отложенным решением.
- Тесты `ask-client` в этом workspace не запускаются (см. решение 1).
