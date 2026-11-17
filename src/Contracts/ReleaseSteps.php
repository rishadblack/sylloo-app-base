<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;

/**
 * What an app adds to the release clean-up of one activation ({@see DeviceRelease},
 * device-api.md §3, decision 20 "send first, then remove"). Sylloo POS: stop selling, send its sales, wipe its tables;
 * Sylloo App: sign the business's members out, remove its tokens, PINs, details and its page store.
 */
interface ReleaseSteps
{
    /**
     * The device calls of this activation (read before the clean-up, while the key is still there).
     */
    public function deviceApi(): DeviceApi;

    /**
     * Stop working at once: sign the member out, stop selling. Called at the start and on every step of a release.
     */
    public function stopWork(): void;

    /**
     * Work the office refused (it keeps the device on the Releasing screen until someone resolves it); 0 when none.
     */
    public function refusedWork(): int;

    /**
     * Whether work still waits to be sent (the clean-up waits for it).
     */
    public function hasWaitingWork(): bool;

    /**
     * Send what waits (in the background).
     */
    public function sendWaitingWork(): void;

    /**
     * Remove everything this app keeps of the activation (rows, files, secrets, the page store), keeping only the
     * computer's own settings (printer, language, window size). The activation itself is forgotten after it.
     */
    public function removeLocalData(): void;

    /**
     * After the clean-up and `POST released` (e.g. start the workers again).
     */
    public function afterClean(): void;
}
