<?php

namespace Rishadblack\SyllooAppBase\Device;

use Closure;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Throwable;

/**
 * Asks the ERP how this device stands (`GET device/api/v1/state`), instead of trusting what the device remembers or
 * what arrives from a page (audit A6, A11): "Check again" on the state screens, the connection test, and commands of
 * the ERP socket. The answer is kept by the activation's {@see DeviceState}.
 */
class DeviceStateCheck
{
    /**
     * @param  Closure(): DeviceApi  $api  builds the device calls when a check runs
     */
    public function __construct(protected DeviceState $device, protected Closure $api) {}

    /**
     * Ask `GET state` and keep the answer. Returns the answer, ['blocked' => 'device_released'] when the key no
     * longer works, or null when the ERP could not be asked (or the device is not activated).
     *
     * @return array<string, mixed>|null
     */
    public function askState(): ?array
    {
        if (! $this->device->isActivated()) {
            return null;
        }

        try {
            $response = ($this->api)()->deviceState();
        } catch (Throwable $exception) {
            Log::info('Device state: the ERP could not be reached: '.$exception->getMessage());

            return null;
        }

        if (DeviceState::isReleasedReply($response)) {
            $this->device->markReleasing();

            return ['blocked' => 'device_released'];
        }

        $state = $response->successful() ? $response->json() : null;

        if (! is_array($state) || ! array_key_exists('blocked', $state)) {
            return null;
        }

        $this->device->remember($state);

        return $state;
    }

    /**
     * Whether an answer confirms the device was released (a state, or a till's heartbeat answer).
     *
     * @param  array<string, mixed>|null  $answer
     */
    public static function confirmsRelease(?array $answer): bool
    {
        return ($answer['status'] ?? null) === 'released'
            || ($answer['blocked'] ?? null) === 'device_released'
            || ($answer['device']['blocked'] ?? null) === 'device_released';
    }
}
