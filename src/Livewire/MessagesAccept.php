<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Rishadblack\SyllooAppBase\Screens\MessagesAcceptScreen;

/**
 * The web face of phone SMS's {@see MessagesAcceptScreen} (the Android till draws its screens in the web view).
 */
class MessagesAccept extends ScreenPage
{
    protected function screenClass(): string
    {
        return MessagesAcceptScreen::class;
    }
}
