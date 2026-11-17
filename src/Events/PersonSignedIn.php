<?php

namespace Rishadblack\SyllooAppBase\Events;

use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;

/**
 * A person is in: after a PIN or after Sign in with browser (with `$person`, the office's answer). The app does its
 * own sign-in (the till: Auth::login and its first pull; the desktop app: opens the business window).
 */
class PersonSignedIn
{
    public function __construct(
        public readonly string $entryId,
        public readonly DeviceMember $member,
        public readonly bool $offline = false,
        public readonly ?SignedInPerson $person = null,
    ) {}
}
