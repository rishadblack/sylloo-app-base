<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\AboutScreen;

/**
 * The web face of About ({@see AboutScreen}).
 */
class About extends ScreenPage
{
    protected function screenClass(): string
    {
        return AboutScreen::class;
    }
}
