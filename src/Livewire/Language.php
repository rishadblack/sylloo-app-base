<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\LanguageScreen;

/**
 * The web face of the language choice ({@see LanguageScreen}).
 */
class Language extends ScreenPage
{
    protected function screenClass(): string
    {
        return LanguageScreen::class;
    }
}
