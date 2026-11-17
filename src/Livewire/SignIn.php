<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Livewire\Attributes\On;
use Rishadblack\SyllooAppBase\Screens\ScreenState;
use Rishadblack\SyllooAppBase\Screens\SignInScreen;

/**
 * The web face of Sign in (step 2) ({@see SignInScreen}). NativePHP's `OpenedFromURL` (the browser's hand-back
 * `<scheme>://signed-in`) reaches it as a Livewire event; another face calls {@see self::openedFromUrl()} itself.
 */
class SignIn extends ScreenPage
{
    protected function screenClass(): string
    {
        return SignInScreen::class;
    }

    #[On('native:Native\Desktop\Events\App\OpenedFromURL')]
    public function openedFromUrl(mixed $url = null): void
    {
        $screen = $this->screen();
        assert($screen instanceof SignInScreen);

        $this->keep($screen->openedFromLink(ScreenState::fromArray($this->state), $url));
    }
}
