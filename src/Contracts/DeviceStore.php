<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Device\DeviceState;

/**
 * Where one activation of a device keeps what it knows: the device key (already encrypted by ActivationKey), the ids,
 * the mode, the business and the state the ERP sent ({@see DeviceState} names
 * every key). Sylloo POS keeps them in the NativePHP settings (one activation); Sylloo App keeps one store per business
 * in its own database.
 *
 * A store holds plain values (string, int, bool, null, arrays of them). A value that was never set reads as null.
 */
interface DeviceStore
{
    /**
     * The value kept under the key, or null. May throw when the store can not be read; DeviceState reads it as null.
     */
    public function get(string $key): mixed;

    /**
     * Keep a value under the key; null forgets it.
     */
    public function set(string $key, mixed $value): void;
}
