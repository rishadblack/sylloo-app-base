<?php

namespace Rishadblack\SyllooAppBase\Device;

use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;

/**
 * One activation the app holds: its id, what it knows ({@see DeviceState}), its people ({@see MemberDirectory}) and
 * what it adds to a release ({@see ReleaseSteps}). Made by the app's DeviceEntries.
 */
final class DeviceEntry
{
    public function __construct(
        public readonly string $id,
        public readonly DeviceState $state,
        public readonly MemberDirectory $members,
        public readonly ReleaseSteps $release,
    ) {}

    /**
     * The office calls of this activation, carrying a person's token when given.
     */
    public function office(?string $token = null): OfficeClient
    {
        return OfficeClient::for($this->state, $token);
    }

    /**
     * The PIN rules of this activation's people.
     */
    public function pins(): PinSignIn
    {
        return new PinSignIn($this->members, $this->state);
    }

    /**
     * The release frame of this activation ("send what waits → remove everything → tell the office").
     */
    public function releaseFrame(): DeviceRelease
    {
        return new DeviceRelease($this->state, $this->release, $this->id);
    }

    /**
     * "XYZ Trading · Staff" for the Businesses list; the device name before the office named the business.
     */
    public function label(): string
    {
        $name = $this->state->businessName() ?? $this->state->deviceName() ?? (string) __('sylloo-app-base::device.this_business');
        $mark = self::kindMark($this->state->kind());

        return $mark !== null ? $name.' · '.$mark : $name;
    }

    /**
     * The STAFF / EMPLOYEE mark of a kind (plan §7.2), or null for a till.
     */
    public static function kindMark(?string $kind): ?string
    {
        return match ($kind) {
            'employee' => (string) __('sylloo-app-base::device.kind_employee'),
            'app' => (string) __('sylloo-app-base::device.kind_staff'),
            default => null,
        };
    }
}
