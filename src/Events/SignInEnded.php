<?php

namespace Rishadblack\SyllooAppBase\Events;

/**
 * A person's sign-in on this device ended: `wrong_pin` (five wrong PINs), `signed_out` (Sign out and switch),
 * `session_expired` or `signed_in_elsewhere` (the office said so). The app signs them out of its own session.
 */
class SignInEnded
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $memberKey,
        public readonly string $reason,
    ) {}
}
