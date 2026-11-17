<?php

namespace Rishadblack\SyllooAppBase\Contracts;

/**
 * Shows a system notification (Windows: NativePHP desktop's Notification; Android: NativePHP mobile's). The base never
 * calls a platform directly.
 */
interface Notifier
{
    /**
     * Show a notification; a click on one with a reference comes back to the app with that reference.
     */
    public function notify(string $title, string $message, ?string $reference = null): void;
}
