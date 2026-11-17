<?php

namespace Rishadblack\SyllooAppBase\Contracts;

/**
 * Opens an address in the system browser (plan §4.3 "Open the browser"): Windows' default browser
 * (NativePHP desktop's Shell::openExternal), a Chrome tab on the phone (NativePHP mobile's Browser plugin). The base
 * only opens https addresses the office sent (http outside production).
 */
interface BrowserOpener
{
    public function open(string $url): void;
}
