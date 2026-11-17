<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;

/**
 * Where an app keeps the people signed in on one activation and their PIN hashes (used by
 * {@see PinSignIn}). Sylloo POS: `users` + the staff list `pos_staff`; Sylloo App: its
 * members table of one business, the hash encrypted.
 */
interface MemberDirectory
{
    /**
     * The member's PIN hash kept here (bcrypt), or null when there is none that can be read.
     */
    public function pinHash(DeviceMember $member): ?string;

    /**
     * Keep a bcrypt PIN hash for the member (already checked to be bcrypt).
     */
    public function rememberPinHash(DeviceMember $member, string $hash): void;

    /**
     * Forget the member's PIN hash (the office says the member has no PIN).
     */
    public function forgetPinHash(DeviceMember $member): void;

    /**
     * The office sent the member's photo (https address or null) and it differs from the kept one: keep it (and its
     * local copy).
     */
    public function rememberPhoto(DeviceMember $member, ?string $photo): void;

    /**
     * Every member signed in on this activation (the name tiles).
     *
     * @return iterable<DeviceMember>
     */
    public function signedInMembers(): iterable;

    /**
     * The device calls carrying this member's token (`me`, `pin`, `sign-out`).
     */
    public function deviceApi(DeviceMember $member): DeviceApi;

    /**
     * Keep the person a browser sign-in brought (a member or an employee): their details, the token until it ends and
     * the PIN hash when the office sent one. Returns them as this directory's member (the name tile from now on).
     */
    public function keepSignIn(SignedInPerson $person): DeviceMember;

    /**
     * The member with this key ({@see DeviceMember::memberKey()}), signed in here or not, or null.
     */
    public function member(string $key): ?DeviceMember;
}
