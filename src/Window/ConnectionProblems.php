<?php

namespace Rishadblack\SyllooAppBase\Window;

use Rishadblack\SyllooAppBase\Contracts\WindowConnection;
use Rishadblack\SyllooAppBase\Support\Texts;
use Throwable;

/**
 * Connection problems of the business window (moved from Sylloo App in base 1.1.0; the Tauri app's checklist,
 * app-plan.md §8): a short drop while a page is open shows a thin bar and keeps the page; a page that cannot load
 * shows the app's own page that says whose problem it is (the internet, or Sylloo); once the internet is there it is
 * tried again by itself and goes back to the page the member wanted; a "Try again" button does the same. A failed
 * one-use ticket is never loaded twice: the window closes and the app opens it at a new ticket.
 *
 * Plain logic over {@see WindowConnection}; the app's window page keeps the status and draws the components
 * `<x-sylloo-app-base::window.offline-bar>` and `<x-sylloo-app-base::window.problem>`. Statuses: {@see self::OK},
 * the ticket's {@see self::UNREACHABLE} (the office gave no answer), {@see self::LOAD_FAILED}.
 */
class ConnectionProblems
{
    public const OK = 'ok';

    /** No answer when the ticket was asked for (no internet, the office is down): tried again by itself. */
    public const UNREACHABLE = 'unreachable';

    /** The business page could not load: the app's own page, tried again by itself. */
    public const LOAD_FAILED = 'load_failed';

    public function __construct(protected WindowConnection $window) {}

    /**
     * The window page's beat (and its first look after opening, with `$retry` false): the bar while the internet is
     * off; a failed page found (also when its event was missed) is hidden; a page that could not load, or an office
     * that gave no answer, is tried again once the internet is there.
     */
    public function check(string $businessId, string $status, bool $retry = true): ConnectionCheck
    {
        $connection = $this->quietly(fn () => $this->window->connection($businessId));

        if (! is_array($connection)) {
            return new ConnectionCheck;
        }

        $online = $connection['online'];

        if ($status === self::OK && $connection['failed']) {
            $this->quietly(fn () => $this->window->hide($businessId));

            return new ConnectionCheck(loadFailed: true, failedOffline: ! $online);
        }

        return new ConnectionCheck(
            offline: $status === self::OK && ! $online,
            tryAgain: $retry && $online && in_array($status, [self::LOAD_FAILED, self::UNREACHABLE], true),
        );
    }

    /**
     * "Try again" for a page that could not load: true when the same page was loaded again (the status is OK again).
     * False when a new ticket is needed (the failed page was the ticket, or nothing failed): a window that failed is
     * closed first (its page store stays), and the app opens it at a new ticket.
     */
    public function tryAgain(string $businessId, string $status): bool
    {
        if ($status !== self::LOAD_FAILED) {
            return false;
        }

        if ($this->quietly(fn () => $this->window->retry($businessId)) === true) {
            return true;
        }

        $this->quietly(fn () => $this->window->close($businessId));

        return false;
    }

    /**
     * Whose problem it is, for the app's own page.
     */
    public static function message(bool $offline): string
    {
        return $offline
            ? Texts::get('window_load_failed_offline')
            : Texts::get('window_load_failed_office', ['status' => (string) config('sylloo-app-base.window.status_page', 'status.sylloo.app')]);
    }

    /**
     * Whether the app tries again by itself in this status (the line under the message).
     */
    public static function triesAgain(string $status): bool
    {
        return in_array($status, [self::LOAD_FAILED, self::UNREACHABLE], true);
    }

    /**
     * A window that does not answer never breaks the page: nothing is decided then.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T|null
     */
    protected function quietly(callable $step): mixed
    {
        try {
            return $step();
        } catch (Throwable) {
            return null;
        }
    }
}
