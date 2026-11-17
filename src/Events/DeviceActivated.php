<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * A device was activated with a code (the confirm card shows next). The app starts its own work: the till starts its
 * workers and fetches its terminal profile; the mobile app sets Staff or Employee mode from `device.kind`.
 */
class DeviceActivated
{
    /**
     * @param  array<string, mixed>  $answer  the office's `activate` answer (the device key removed)
     */
    public function __construct(
        public readonly string $entryId,
        public readonly array $answer,
    ) {}
}
