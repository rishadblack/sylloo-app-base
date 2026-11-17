<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\LockScreen;

/**
 * The web face of the idle lock ({@see LockScreen}).
 */
class Lock extends ScreenPage
{
    protected function screenClass(): string
    {
        return LockScreen::class;
    }
}
