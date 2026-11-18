<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\MessagesScreen;

/**
 * The web face of phone SMS's {@see MessagesScreen} (the Android till draws its screens in the web view).
 */
class Messages extends ScreenPage
{
    protected function screenClass(): string
    {
        return MessagesScreen::class;
    }
}
