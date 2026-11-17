<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\BrowserOpener;

/**
 * A browser for tests: it keeps the addresses the app asked it to open. Bind it with {@see self::bind()}.
 */
final class RecordingBrowser implements BrowserOpener
{
    /** @var list<string> */
    public array $opened = [];

    public static function bind(): self
    {
        $browser = new self;
        app()->instance(BrowserOpener::class, $browser);

        return $browser;
    }

    public function open(string $url): void
    {
        $this->opened[] = $url;
    }

    public function last(): ?string
    {
        return $this->opened === [] ? null : $this->opened[count($this->opened) - 1];
    }
}
