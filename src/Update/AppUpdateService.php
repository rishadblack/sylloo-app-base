<?php

namespace Rishadblack\SyllooAppBase\Update;

use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\AppUpdater;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * Tells the user about new app versions, both when the app starts and while it is open.
 *
 * The platform checks for an update on every launch; while the app runs, the scheduler checks
 * hourly and the ERP can ask for a check over the socket. Each version is announced once per
 * launch: "available" when the download starts, "ready" when it can be installed.
 *
 * The user can also check by hand (tray panel and app menu); that check always answers,
 * including "up to date" and "couldn't check".
 *
 * Platform-neutral: the updater and the notices are the app's ({@see AppUpdater}, {@see Notifier}). The app reports
 * its updater's events to {@see self::updateAvailable()}, {@see self::updateDownloaded()},
 * {@see self::updateNotAvailable()} and {@see self::updateFailed()}, and a click on the {@see self::READY_NOTIFICATION}
 * notice to {@see self::installNow()}. It names itself in the notices ({@see self::productName()}) and may add work when
 * an update is ready ({@see self::rememberUpdateReady()}) or before it installs ({@see self::beforeInstall()}). The
 * running version is AppInfo::version().
 */
class AppUpdateService
{
    public const READY_NOTIFICATION = 'app-update-ready';

    public const NOTIFIED_CACHE_KEY = 'desktop.update_notified';

    /**
     * Set while a check started by the app itself runs. The platform's launch check shows its own
     * "ready to install" notification, so ours is only needed for these checks.
     */
    public const APP_CHECK_CACHE_KEY = 'desktop.update_check_by_app';

    /**
     * Set while a check the user started by hand runs, so its outcome is always reported.
     */
    public const MANUAL_CHECK_CACHE_KEY = 'desktop.update_check_manual';

    public function __construct(protected AppUpdater $updater, protected Notifier $notifier) {}

    public function checkForUpdates(bool $manual = false): void
    {
        if (! $this->updater->isEnabled()) {
            if ($manual) {
                $this->notifier->notify($this->text('update_off_title'), $this->text('update_off_text'));
            }

            return;
        }

        cache()->put(static::APP_CHECK_CACHE_KEY, true, now()->addMinutes(30));

        if ($manual) {
            cache()->put(static::MANUAL_CHECK_CACHE_KEY, true, now()->addMinutes(5));
        }

        $this->updater->checkForUpdates();
    }

    /**
     * Forget what was announced, so a new launch announces a pending update again.
     */
    public static function resetNotifications(): void
    {
        cache()->forget(static::NOTIFIED_CACHE_KEY);
    }

    /**
     * The updater found a new version and downloads it. Takes the version, or the updater's event (NativePHP's
     * `UpdateAvailable`, any object with a `version`).
     */
    public function updateAvailable(string|object $version): void
    {
        $version = self::versionOf($version);
        $isManualCheck = (bool) cache()->pull(static::MANUAL_CHECK_CACHE_KEY);

        if (! $this->firstNotice('available', $version) && ! $isManualCheck) {
            return;
        }

        $this->notifier->notify($this->text('update_available_title', $version), $this->text('update_available_text'));
    }

    /**
     * The new version was downloaded and can be installed. Takes the version, or the updater's event (NativePHP's
     * `UpdateDownloaded`).
     */
    public function updateDownloaded(string|object $version): void
    {
        $version = self::versionOf($version);
        $this->rememberUpdateReady($version);

        if (! cache()->pull(static::APP_CHECK_CACHE_KEY) || ! $this->firstNotice('ready', $version)) {
            return;
        }

        $this->notifier->notify(
            $this->text('update_ready_title', $version),
            $this->text('update_ready_text'),
            self::READY_NOTIFICATION,
        );
    }

    public function installNow(): void
    {
        $this->beforeInstall();

        $this->updater->quitAndInstall();
    }

    /**
     * The updater found nothing new (the updater's event may be passed; it says nothing more).
     */
    public function updateNotAvailable(?object $event = null): void
    {
        if (! cache()->pull(static::MANUAL_CHECK_CACHE_KEY)) {
            return;
        }

        $this->notifier->notify($this->text('update_none_title'), $this->text('update_none_text', AppInfo::version()));
    }

    /**
     * The updater could not check or download. Takes the message, or the updater's event (NativePHP's `UpdateError`,
     * with `message` and `name`).
     */
    public function updateFailed(string|object $message, ?string $name = null): void
    {
        if (is_object($message)) {
            $name ??= self::stringOf($message, 'name');
            $message = self::stringOf($message, 'message') ?? 'unknown error';
        }

        Log::warning("App update failed: {$message}", ['name' => $name]);

        if (cache()->pull(static::MANUAL_CHECK_CACHE_KEY)) {
            $this->notifier->notify($this->text('update_failed_title'), $this->text('update_failed_text'));
        }
    }

    /**
     * The version of an updater's event (`version`, or `info.version`), or the version itself.
     */
    public static function versionOf(string|object $event): string
    {
        if (is_string($event)) {
            return $event;
        }

        $version = self::stringOf($event, 'version');

        if ($version === null && isset($event->info)) {
            $info = $event->info;
            $version = is_array($info) && is_string($info['version'] ?? null) ? $info['version'] : (is_object($info) ? self::stringOf($info, 'version') : null);
        }

        return $version ?? '';
    }

    protected static function stringOf(object $event, string $property): ?string
    {
        $value = isset($event->{$property}) ? $event->{$property} : null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A notice (`sylloo-app-base::device.<key>`) in config `sylloo-app-base.texts.locale`, else the app's language, with the
     * app's name and a version.
     */
    protected function text(string $key, ?string $version = null): string
    {
        return Texts::office($key, ['app' => $this->productName(), 'version' => (string) $version]);
    }

    /**
     * The app's name in the notices.
     */
    protected function productName(): string
    {
        return (string) config('app.name');
    }

    /**
     * A new version was downloaded and installs on the next start (e.g. the till shows it in its sync panel).
     */
    protected function rememberUpdateReady(string $version): void {}

    /**
     * Work before the app quits to install (e.g. the till keeps a copy of its database first).
     */
    protected function beforeInstall(): void {}

    /**
     * True the first time this launch that the given notice is shown for this version.
     */
    protected function firstNotice(string $notice, string $version): bool
    {
        $notified = cache()->get(static::NOTIFIED_CACHE_KEY, []);

        if (($notified[$notice] ?? null) === $version) {
            return false;
        }

        cache()->forever(static::NOTIFIED_CACHE_KEY, [...$notified, $notice => $version]);

        return true;
    }
}
