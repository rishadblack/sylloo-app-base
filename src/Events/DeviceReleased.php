<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * A released device finished its clean-up: everything of the activation was removed and the office was told. The
 * Activate screen shows next ("This device was released from …").
 */
class DeviceReleased
{
    public function __construct(
        public readonly string $entryId,
        public readonly ?string $business,
    ) {}
}
