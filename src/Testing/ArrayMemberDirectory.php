<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;

/**
 * The members of one activation and their PIN hashes, in memory (an app's tests, and the base's). A person signed in
 * with the browser is kept as a {@see FakeMember} keyed `<kind>-<id>` (member-…, employee-…).
 */
class ArrayMemberDirectory implements MemberDirectory
{
    /** @var array<string, string> */
    public array $hashes = [];

    /** @var list<DeviceMember> */
    public array $members = [];

    public function __construct(public DeviceState $device) {}

    public function pinHash(DeviceMember $member): ?string
    {
        return $this->hashes[$member->memberKey()] ?? null;
    }

    public function rememberPinHash(DeviceMember $member, string $hash): void
    {
        $this->hashes[$member->memberKey()] = $hash;
    }

    public function forgetPinHash(DeviceMember $member): void
    {
        unset($this->hashes[$member->memberKey()]);
    }

    public function rememberPhoto(DeviceMember $member, ?string $photo): void
    {
        if ($member instanceof FakeMember) {
            $member->photo = $photo;
        }
    }

    public function signedInMembers(): iterable
    {
        return array_filter($this->members, fn (DeviceMember $member) => $member->hasValidToken());
    }

    public function deviceApi(DeviceMember $member): DeviceApi
    {
        return new DeviceApi(new ApiService($this->device, $member instanceof FakeMember ? $member->token : null));
    }

    public function keepSignIn(SignedInPerson $person): DeviceMember
    {
        $key = $person->kind.'-'.$person->id;
        $member = $this->member($key);

        if ($member instanceof FakeMember) {
            $member->name = $person->name;
            $member->token = $person->token;
            $member->tokenExpiresAt = $person->expiresAt;
            $member->photo = $person->photo;

            return $member;
        }

        return $this->members[] = new FakeMember($key, $person->name, $person->token, $person->expiresAt, null, $person->photo);
    }

    public function member(string $key): ?DeviceMember
    {
        foreach ($this->members as $member) {
            if ($member->memberKey() === $key) {
                return $member;
            }
        }

        return null;
    }
}
