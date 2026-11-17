<?php

namespace Rishadblack\SyllooAppBase\Livewire\Modals;

use Rishadblack\SyllooAppBase\Livewire\ScreenModal;
use Rishadblack\SyllooAppBase\Screens\LanguageScreen;

/**
 * the language choice in a modal ({@see LanguageScreen}).
 */
class LanguageModal extends ScreenModal
{
    protected function screenClass(): string
    {
        return LanguageScreen::class;
    }
}
