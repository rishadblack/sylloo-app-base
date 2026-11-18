<?php

namespace Rishadblack\SyllooAppBase\Contracts;

/**
 * The app's handle on its business window, as far as connection problems need it ({@see
 * \Rishadblack\SyllooAppBase\Window\ConnectionProblems}). The desktop app answers through Electron, the mobile app
 * through its web view. Every method takes the business (entry) id; a window that is not open answers as such.
 */
interface WindowConnection
{
    /**
     * Whether this device is online and whether the window's last page could not load; null when the window could not
     * be asked (nothing is decided then).
     *
     * @return array{online: bool, failed: bool}|null
     */
    public function connection(string $businessId): ?array;

    /** Hide the page (it stays loaded) so the app's own page shows. */
    public function hide(string $businessId): void;

    /**
     * Load again the page that could not load (the member's own page, with the window's cookies, no new ticket) and
     * show the window. False when there is nothing to load again or the failed page was a one-use ticket (a ticket is
     * never loaded twice): then a new ticket is needed.
     */
    public function retry(string $businessId): bool;

    /** Close the window (its page store stays), so it opens again at a new ticket. */
    public function close(string $businessId): void;
}
