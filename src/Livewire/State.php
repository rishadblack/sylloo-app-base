<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\StateScreen;

/**
 * The web face of the state screens ({@see StateScreen}).
 */
class State extends ScreenPage
{
    protected function screenClass(): string
    {
        return StateScreen::class;
    }
}
