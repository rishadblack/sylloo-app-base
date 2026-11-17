<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\ActivateScreen;

/**
 * The web face of Activate (step 1) ({@see ActivateScreen}).
 */
class Activate extends ScreenPage
{
    protected function screenClass(): string
    {
        return ActivateScreen::class;
    }
}
