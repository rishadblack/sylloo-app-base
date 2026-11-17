<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\DeviceStore;

/**
 * A device store in memory: one activation (or one business of a multi-business app).
 */
class ArrayDeviceStore implements DeviceStore
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public array $values = []) {}

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->values[$key]);

            return;
        }

        $this->values[$key] = $value;
    }
}
