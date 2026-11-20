<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\AppUpdater;
use Rishadblack\SyllooAppBase\Device\DeviceEntry;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * About: the app, its version and system, and this activation (business, LIVE / PRACTICE, STAFF / EMPLOYEE, device
 * name and number). "Check for updates" when the app has an updater. Hook place: `about.rows`. Step: `about`.
 */
class AboutScreen extends Screen
{
    public function name(): string
    {
        return 'about';
    }

    public function start(array $input = []): ScreenState
    {
        return $this->begin('about');
    }

    public function view(ScreenState $state): ScreenView
    {
        $device = $this->device();
        $activated = $device->isActivated();

        return new ScreenView(
            title: $this->t('about_title'),
            text: AppInfo::name(),
            buttons: app()->bound(AppUpdater::class) ? [new Button('update_now', $this->t('check_updates'), 'outline-primary', 'bi bi-cloud-arrow-down')] : [],
            notices: $this->notices($state),
            rows: [
                $this->t('about_version') => Texts::digits(AppInfo::displayVersion()),
                $this->t('about_platform') => AppInfo::platform(),
                $this->t('about_business') => $activated ? $device->businessName() : $this->t('about_not_activated'),
                $this->t('about_mode') => $activated ? ($device->isPractice() ? $this->t('mode_practice') : $this->t('mode_live')) : null,
                $this->t('about_kind') => $activated ? DeviceEntry::kindMark($device->kind()) : null,
                $this->t('about_device') => $activated ? trim(($device->deviceName() ?? '').' #'.($device->get('device_id') ?? ''), ' #') : null,
            ],
            places: ['about.rows'],
            icon: 'bi bi-info-circle',
        );
    }

    protected function doUpdateNow(ScreenState $state): void
    {
        $this->updateNow($state);
    }
}
