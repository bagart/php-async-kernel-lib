<?php

declare(strict_types=1);

namespace Illuminate\Contracts\Cache;

// illuminate/contracts is not a dependency of the kernel lib — the Store
// interface only exists in the host Laravel app, so stub it here to make
// ASKCacheWrapper::getStore() delegation testable.
if (!interface_exists(Store::class)) {
    interface Store
    {
    }
}
