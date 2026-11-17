<?php

namespace Rishadblack\SyllooAppBase\Device;

use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Support\Texts;
use Throwable;

/**
 * The ERP's connection test of this device (ERP Settings › Devices › Test, ../sylloo-erp-docs/erp/device-test-plan.md).
 * It arrives on the device's socket as the command `device_test`; the device checks itself and answers with
 * `POST device/api/v1/test-result`. Shared checks: `connection` (GET state), `socket` (the test came over it),
 * `notification` (an OS notification shows); an app adds its own ({@see self::moreChecks()}).
 */
abstract class DeviceTestRunner
{
    /**
     * The device calls of the activation under test (with its device key).
     */
    abstract protected function deviceApi(): DeviceApi;

    /**
     * Ask `GET state` (DeviceStateCheck::askState()): the answer, or null when the ERP could not be asked.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function askState(): ?array;

    /**
     * Runs the checks and sends them. Returns the checks and whether the ERP took the answer.
     *
     * @return array{checks: list<array{key: string, ok: bool, detail?: string}>, sent: bool}
     */
    public function run(string $testId): array
    {
        $checks = [];

        $state = $this->attempt(fn () => $this->askState());
        $checks[] = $this->check('connection', is_array($state), Texts::office('device_test_unreachable'));

        $checks[] = $this->check('socket', true);

        $shown = $this->attempt(function () {
            app(Notifier::class)->notify(Texts::office('device_test_title'), $this->notificationText());

            return true;
        });
        $checks[] = $this->check('notification', $shown === true, Texts::office('device_test_notice_failed'));

        foreach ($this->moreChecks() as $check) {
            $checks[] = $check;
        }

        $sent = (bool) $this->attempt(fn () => $this->deviceApi()->deviceTestResult($testId, array_slice($checks, 0, 40))->successful());

        return ['checks' => $checks, 'sent' => $sent];
    }

    /**
     * The text of the test notification.
     */
    protected function notificationText(): string
    {
        return Texts::office('device_test_text');
    }

    /**
     * The app's own checks, after the shared ones (keys `[a-z0-9_.]{1,48}`, at most 40 checks in all).
     *
     * @return list<array{key: string, ok: bool, detail?: string}>
     */
    protected function moreChecks(): array
    {
        return [];
    }

    /**
     * @return array{key: string, ok: bool, detail?: string}
     */
    protected function check(string $key, bool $ok, ?string $whenFailed = null, ?string $whenPassed = null): array
    {
        $detail = $ok ? $whenPassed : $whenFailed;

        return $detail === null ? ['key' => $key, 'ok' => $ok] : ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * Run one check; a check that throws counts as failed (null).
     */
    protected function attempt(callable $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            Log::info('Device test: a check failed: '.$exception->getMessage());

            return null;
        }
    }
}
