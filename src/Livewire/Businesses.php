<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\BusinessesScreen;

/**
 * The web face of Businesses ({@see BusinessesScreen}).
 */
class Businesses extends ScreenPage
{
    protected function screenClass(): string
    {
        return BusinessesScreen::class;
    }
}
