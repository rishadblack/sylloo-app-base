<?php

namespace Rishadblack\SyllooAppBase\Livewire\Modals;

use Rishadblack\SyllooAppBase\Livewire\ScreenModal;
use Rishadblack\SyllooAppBase\Screens\AboutScreen;

/**
 * About in a modal ({@see AboutScreen}).
 */
class AboutModal extends ScreenModal
{
    protected function screenClass(): string
    {
        return AboutScreen::class;
    }
}
