<?php

namespace Rishadblack\SyllooAppBase\Rest;

/**
 * What one send of the waiting list did: how many went, how many the office refused (kept with their reason), how
 * many still wait, whether it stopped because the office could not be reached, and the gate's code when the door
 * refused the device or the person (`session_expired`, `device_released`, `app_too_old`, …: the app deals with it,
 * the writes keep waiting).
 */
final class SendReport
{
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $refused = 0,
        public readonly int $left = 0,
        public readonly bool $unreachable = false,
        public readonly ?string $stoppedBy = null,
    ) {}
}
