<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\PinScreen;

/**
 * The web face of PIN pad (step 3) ({@see PinScreen}).
 */
class Pin extends ScreenPage
{
    protected function screenClass(): string
    {
        return PinScreen::class;
    }
}
