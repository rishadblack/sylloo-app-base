<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Update\AppUpdateService;

/**
 * The platform's updater (Windows: NativePHP desktop's AutoUpdater with Sylloo's signature check; Android: the app's
 * own installer). The app reports its outcome to {@see AppUpdateService}.
 */
interface AppUpdater
{
    /**
     * Whether this build checks for updates at all.
     */
    public function isEnabled(): bool;

    /**
     * Look for a new version (it downloads in the background and reports back).
     */
    public function checkForUpdates(): void;

    /**
     * Quit and install the downloaded version.
     */
    public function quitAndInstall(): void;
}
