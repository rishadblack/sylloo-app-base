<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * The device was locked (idle time, "Lock now"): the app hides what is on screen (the till keeps the order).
 */
class DeviceLocked
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $memberKey,
    ) {}
}
