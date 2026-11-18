<?php

namespace Rishadblack\SyllooAppBase\Contracts;

/**
 * The phone's side of phone SMS (phone-messaging-plan.md §5; device-api.md §7) for the shared Messages screens. The
 * base knows no platform: an Android app binds the phone package's answer (`Rishadblack\SyllooAppNative\Messages`),
 * which runs the Kotlin jobs `Sms.*` and `Messages.*`. Without a binding the screens say this phone can not send SMS.
 */
interface MessagingPhone
{
    /**
     * Whether this build can send SMS at all (the app turned the job on, and it runs on a phone).
     */
    public function available(): bool;

    /**
     * Whether Android's SMS permission (and the one to list the SIMs) is given.
     */
    public function hasPermission(): bool;

    /**
     * Ask Android's own question for the SMS permission; true when the question was shown (the answer comes later:
     * ask {@see self::hasPermission()} again).
     */
    public function askPermission(): bool;

    /**
     * The phone's SIMs: `[['slot' => 0, 'label' => 'SIM 1 · Grameenphone'], …]`; null before the permission is given.
     * Android gives no SIM number, so a SIM is named by its slot and operator.
     *
     * @return list<array{slot: int, label: string}>|null
     */
    public function sims(): ?array;

    /**
     * Hand the device key and the office's address to the background job and start it (after the yes, and at every
     * return to the app). False when the phone refused.
     */
    public function start(string $baseUrl, string $deviceKey, string $appVersion): bool;

    /**
     * Run the background job now (ask the office what waits).
     */
    public function wake(): bool;

    /**
     * Stop the background job and forget its key, its settings and its counts.
     */
    public function stop(): bool;

    /**
     * What the phone knows now: `online`, `can_send`, `sim_present`, `service` (null = ready, else `no_signal`,
     * `no_service`, `radio_off`, `no_sim`, `permission`), `last_stop`, `last_run_at`, `sent_today`, `failed_today`.
     *
     * @return array<string, mixed>
     */
    public function status(?int $slot = null): array;
}
