<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;

final class TickableExtractor
{
    /**
     * @template T of object
     *
     * @param  list<T|null>  $objects
     *
     * @return list<ASKTickableContract>
     */
    public static function extract(array $objects): array
    {
        $result = [];

        foreach ($objects as $object) {
            if ($object instanceof WithASKTickableContract) {
                foreach ($object->tickable() as $sub) {
                    if ($sub !== $object) {
                        $result[] = $sub;
                    }
                }
            }

            if ($object instanceof ASKTickableContract) {
                $result[] = $object;
            }
        }

        return $result;
    }
}
