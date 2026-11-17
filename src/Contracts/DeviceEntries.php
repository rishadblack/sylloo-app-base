<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Device\DeviceEntry;

/**
 * The activations this app holds (plan §6.1 #4 "Several businesses", §7.1 one phone with a Staff and an Employee
 * entry). The shared screens always work on {@see self::current()}. A one-business app (the till) has one entry and
 * may keep the base's default (Device\SingleDeviceEntries, built from the bound DeviceStore, MemberDirectory and
 * ReleaseSteps).
 */
interface DeviceEntries
{
    /**
     * The entry the screens work on now. An app with no entry yet makes an empty one (it shows Activate).
     */
    public function current(): DeviceEntry;

    /**
     * Every entry, the current one included.
     *
     * @return list<DeviceEntry>
     */
    public function all(): array;

    /**
     * Work on this entry from now on (Businesses › open).
     */
    public function select(string $id): void;

    /**
     * A new empty entry for "Add a business", made current (it shows Activate).
     */
    public function add(): DeviceEntry;

    /**
     * Forget an entry that was released and cleaned (its store is empty).
     */
    public function forget(string $id): void;
}
