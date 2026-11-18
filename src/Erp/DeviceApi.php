<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Illuminate\Http\Client\Response;

/**
 * The device calls both desktop apps make (`https://<ERP base>/device/api/v1/…`, ../sylloo-erp-docs/erp/device-api.md
 * §1). Each is tried once with a short timeout (the user waits) and always tried, even while background calls wait for
 * the ERP ({@see ErpCircuitBreaker}). Refusals answer `{message, code, …}`; read them with {@see Refusal}.
 *
 * Build it on an {@see ApiService} that carries the activation's device key and, for the member calls (`pin`, `me`,
 * `sign-out`), the member's token: `new DeviceApi(new ApiService($deviceState, $token))`.
 */
class DeviceApi
{
    /**
     * Seconds a call the user waits on (activation, browser sign-in) is given.
     */
    public const LOGIN_TIMEOUT_SECONDS = 15;

    public function __construct(protected ApiService $api) {}

    /**
     * A call that always tries, even while background calls wait for the ERP (see ErpCircuitBreaker).
     */
    protected function probe(): ApiService
    {
        return (clone $this->api)->asProbe();
    }

    /**
     * A call to the device API (`device/api/v1/…`), always tried.
     */
    protected function device(): ApiService
    {
        return $this->probe()->onDeviceApi();
    }

    /**
     * Activate this device with the business code (device-api.md §1 `POST activate`). One try: the user waits.
     *
     * @param  array<string, mixed>  $payload  code, kind, device_uid, name, platform, os, app_version (+ a till's queue counts)
     */
    public function activateDevice(array $payload): Response
    {
        return $this->device()->postOnce('v1/activate', $payload, self::LOGIN_TIMEOUT_SECONDS, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * The answer to the ERP's connection test (`POST test-result`, device-test-plan.md): `{test_id, checks}`.
     *
     * @param  list<array{key: string, ok: bool, detail?: string}>  $checks
     */
    public function deviceTestResult(string $testId, array $checks): Response
    {
        return $this->device()->postOnce('v1/test-result', ['test_id' => $testId, 'checks' => $checks], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2);
    }

    /**
     * What the ERP says about this device now: plan, blocked, limits, minimum version (`GET state`).
     */
    public function deviceState(): Response
    {
        return $this->device()->getOnce('v1/state', ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2);
    }

    /**
     * Start a browser sign-in with the SHA-256 (lowercase hex) of a secret the app keeps (`POST sign-in/start`).
     */
    public function signInStart(string $challenge): Response
    {
        return $this->device()->postOnce('v1/sign-in/start', ['challenge' => $challenge], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Ask whether the member pressed Allow (`POST sign-in/finish`): 409 `sign_in_pending` until then.
     */
    public function signInFinish(string $requestId, string $verifier): Response
    {
        return $this->device()->postOnce('v1/sign-in/finish', ['request_id' => $requestId, 'verifier' => $verifier], self::LOGIN_TIMEOUT_SECONDS, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Set the PIN of the member whose token the calls carry (`POST pin`).
     */
    public function setPin(string $pin): Response
    {
        return $this->device()->postOnce('v1/pin', ['pin' => $pin], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * The member whose token the calls carry, with their PIN hash of this side (`GET me`): a PIN changed on another
     * device is known here too. One short try.
     */
    public function me(): Response
    {
        return $this->device()->getOnce('v1/me', ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * End the sign-in of the member whose token the calls carry on this device (`POST sign-out`).
     */
    public function signOut(): Response
    {
        return $this->device()->postOnce('v1/sign-out', [], ApiService::INTERACTIVE_TIMEOUT_SECONDS, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * The device releases itself ("Use another code", "Deactivate this device"; `POST deactivate`).
     */
    public function deactivate(): Response
    {
        return $this->device()->postOnce('v1/deactivate', [], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Tell the ERP this released device removed all its data (`POST released`); the key stops working.
     */
    public function released(): Response
    {
        return $this->device()->postOnce('v1/released', [], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * The phone's push token (Firebase), or null to forget it (`POST push-token`, Sylloo App on Android).
     */
    public function pushToken(?string $token): Response
    {
        return $this->device()->postOnce('v1/push-token', ['token' => $token], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Phone SMS: what waits for this phone, the pairing asked, paused or not, the SIM, the daily limit and the pace
     * (`GET messages`, device-api.md §7; the device key only).
     */
    public function messages(): Response
    {
        return $this->device()->getOnce('v1/messages', ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2);
    }

    /**
     * The member's answer to "May this phone send the business's SMS?" (`POST messages/pairing`, with the member's
     * token): `accept` with the chosen SIM, or `refuse`.
     *
     * @param  array{slot: int, label: string}|null  $sim
     */
    public function messagesPairing(string $answer, ?array $sim = null): Response
    {
        return $this->device()->postOnce('v1/messages/pairing', array_filter(['answer' => $answer, 'sim' => $sim], fn ($value) => $value !== null), ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }

    /**
     * Pause or resume this phone's sending (`POST messages/pause`, with the member's token).
     */
    public function messagesPause(bool $paused): Response
    {
        return $this->device()->postOnce('v1/messages/pause', ['paused' => $paused], ApiService::INTERACTIVE_TIMEOUT_SECONDS * 2, ApiService::INTERACTIVE_TIMEOUT_SECONDS);
    }
}
