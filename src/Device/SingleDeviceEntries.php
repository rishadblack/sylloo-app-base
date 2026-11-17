<?php

namespace Rishadblack\SyllooAppBase\Device;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;

/**
 * The default {@see DeviceEntries} of a one-business app (Sylloo POS): one entry named `default`, built from what the
 * app binds: DeviceStore, MemberDirectory and ReleaseSteps (and DeviceState when it extends it). "Add a business" is
 * not offered by such an app.
 */
class SingleDeviceEntries implements DeviceEntries
{
    public const ID = 'default';

    protected ?DeviceEntry $entry = null;

    public function __construct(protected Container $container) {}

    public function current(): DeviceEntry
    {
        if ($this->entry !== null) {
            return $this->entry;
        }

        foreach ([DeviceStore::class, MemberDirectory::class, ReleaseSteps::class] as $contract) {
            if (! $this->container->bound($contract)) {
                throw new LogicException("Bind {$contract} (or Contracts\\DeviceEntries) in the app before the shared screens run.");
            }
        }

        $state = $this->container->bound(DeviceState::class)
            ? $this->container->make(DeviceState::class)
            : new DeviceState($this->container->make(DeviceStore::class));

        return $this->entry = new DeviceEntry(
            self::ID,
            $state,
            $this->container->make(MemberDirectory::class),
            $this->container->make(ReleaseSteps::class),
        );
    }

    public function all(): array
    {
        return [$this->current()];
    }

    public function select(string $id): void {}

    public function add(): DeviceEntry
    {
        return $this->current();
    }

    public function forget(string $id): void {}
}
