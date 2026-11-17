<?php

namespace Rishadblack\SyllooAppBase\Release;

use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Events\DeviceReleased;
use Throwable;

/**
 * A released device cleans itself (../sylloo-erp-docs/erp/device-api.md §3, plan §6.7, decision 20 "send first, then
 * remove"). The ERP answers `device_released` (401, or `blocked` in the state); then:
 *
 * 1. The device stops working at once (the Releasing screen, DeviceState::screen()) and the member is signed out.
 * 2. It keeps sending what waits (the office still takes it from a released device).
 * 3. When nothing waits it removes everything of the activation, says `POST released` (the key read before the
 *    clean-up), forgets the activation, and the Activate screen shows "This device was released from …".
 *
 * Work the office refused keeps the device on the Releasing screen until someone resolves it. What is sent first and
 * what is removed are the app's ({@see ReleaseSteps}).
 */
class DeviceRelease
{
    /** Not released: nothing to do. */
    public const NONE = 'none';

    /** Work still goes up; the clean-up waits. */
    public const SENDING = 'sending';

    /** The office refused work; the device stays on Releasing until it is resolved. */
    public const REFUSED = 'refused';

    /** Everything was sent and the device is clean. */
    public const CLEANED = 'cleaned';

    /** "Deactivate this device": the office was told and the release runs. */
    public const DEACTIVATED = 'deactivated';

    /** "Deactivate this device" needs the internet. */
    public const OFFLINE = 'offline';

    public function __construct(protected DeviceState $device, protected ReleaseSteps $steps, protected string $entryId = 'default') {}

    /**
     * Stop working and sign the member out: the start of a release (the ERP's answer said `device_released`).
     */
    public function start(): void
    {
        $this->device->markReleasing();

        $this->steps->stopWork();
    }

    /**
     * "Deactivate this device" (menu): the device releases itself at the office (`POST deactivate`), then the same
     * release runs as when the office releases it. Online only: offline nothing changes (an app may remember it and
     * try again later).
     *
     * @return self::DEACTIVATED|self::OFFLINE|self::REFUSED
     */
    public function deactivate(): string
    {
        try {
            $response = $this->steps->deviceApi()->deactivate();
        } catch (Throwable) {
            return self::OFFLINE;
        }

        if (! $response->successful() && ! DeviceState::isReleasedReply($response)) {
            return self::REFUSED;
        }

        $this->start();

        return self::DEACTIVATED;
    }

    /**
     * One step of the release, run on the Releasing screen (page load, Try again, every few seconds).
     *
     * @return self::NONE|self::SENDING|self::REFUSED|self::CLEANED
     */
    public function run(): string
    {
        if (! $this->device->isReleasing()) {
            return self::NONE;
        }

        $this->start();

        if ($this->steps->refusedWork() > 0) {
            return self::REFUSED;
        }

        if ($this->steps->hasWaitingWork()) {
            $this->steps->sendWaitingWork();

            return self::SENDING;
        }

        $this->clean();

        return self::CLEANED;
    }

    /**
     * Remove everything of the activation, then tell the ERP (with the key read before the wipe) and forget it.
     */
    public function clean(): void
    {
        $business = $this->device->businessName();
        $api = $this->steps->deviceApi();

        $this->steps->removeLocalData();

        try {
            $api->released();
        } catch (Throwable $exception) {
            Log::info('Release: the ERP was not told the terminal is clean: '.$exception->getMessage());
        }

        $this->device->forgetActivation();
        $this->device->store()->set(DeviceState::RELEASED_FROM_SETTING, $business ?? '');

        $this->steps->afterClean();

        event(new DeviceReleased($this->entryId, $business));
    }
}
