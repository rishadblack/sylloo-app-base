<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;

/**
 * An app's release steps, recording what the frame asked for. Its local data is one key of the store (`app_data`).
 */
class FakeReleaseSteps implements ReleaseSteps
{
    /** @var list<string> */
    public array $calls = [];

    public int $refused = 0;

    public bool $waiting = false;

    public function __construct(public DeviceState $device) {}

    public function deviceApi(): DeviceApi
    {
        return new DeviceApi(new ApiService($this->device));
    }

    public function stopWork(): void
    {
        $this->calls[] = 'stopWork';
    }

    public function refusedWork(): int
    {
        return $this->refused;
    }

    public function hasWaitingWork(): bool
    {
        return $this->waiting;
    }

    public function sendWaitingWork(): void
    {
        $this->calls[] = 'sendWaitingWork';
    }

    public function removeLocalData(): void
    {
        $this->calls[] = 'removeLocalData';
        $this->device->store()->set('app_data', null);
    }

    public function afterClean(): void
    {
        $this->calls[] = 'afterClean';
    }
}
