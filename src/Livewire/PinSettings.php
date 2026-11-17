<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\PinSettingsScreen;

/**
 * The web face of PIN and lock settings ({@see PinSettingsScreen}).
 */
class PinSettings extends ScreenPage
{
    protected function screenClass(): string
    {
        return PinSettingsScreen::class;
    }
}
