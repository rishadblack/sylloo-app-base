<?php

namespace Rishadblack\SyllooAppBase\Window;

/**
 * What a beat of the window page found ({@see ConnectionProblems::check()}).
 */
final class ConnectionCheck
{
    public function __construct(
        /** Show the thin "No internet" bar (the page and the member's work stay). */
        public readonly bool $offline = false,
        /** The page could not load: it was hidden; show the app's own page ({@see ConnectionProblems::message()}). */
        public readonly bool $loadFailed = false,
        /** Whether the page could not load because the internet is off (else the office did not answer). */
        public readonly bool $failedOffline = false,
        /** The internet is there again: try again now ({@see ConnectionProblems::tryAgain()}). */
        public readonly bool $tryAgain = false,
    ) {}
}
