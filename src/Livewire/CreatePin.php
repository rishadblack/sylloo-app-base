<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\CreatePinScreen;

/**
 * The web face of Create PIN (step 3) ({@see CreatePinScreen}).
 */
class CreatePin extends ScreenPage
{
    protected function screenClass(): string
    {
        return CreatePinScreen::class;
    }
}
