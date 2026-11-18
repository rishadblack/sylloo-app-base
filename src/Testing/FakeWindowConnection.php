<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Rishadblack\SyllooAppBase\Contracts\WindowConnection;
use RuntimeException;

/**
 * A business window for tests of connection problems: online or not, the page that could not load (a one-use ticket
 * address is never loaded again), and what was asked of it. Bind it with {@see self::bind()}.
 */
final class FakeWindowConnection implements WindowConnection
{
    public bool $online = true;

    /** Answer nothing, as a window that can not be asked. */
    public bool $silent = false;

    /** @var array<string, array{failed: ?string, visible: bool, open: bool}> */
    public array $windows = [];

    /** @var list<string> */
    public array $calls = [];

    public static function bind(): self
    {
        $window = new self;
        app()->instance(WindowConnection::class, $window);

        return $window;
    }

    /** The window of a business shows a page. */
    public function opened(string $businessId): self
    {
        $this->windows[$businessId] = ['failed' => null, 'visible' => true, 'open' => true];

        return $this;
    }

    /** A page (by default the ticket address) could not load. */
    public function failLoad(string $businessId, string $url = 'https://demo.sylloo.app/app/session?ticket=t1'): self
    {
        $this->windows[$businessId] = ['failed' => $url, 'visible' => true, 'open' => true];

        return $this;
    }

    /**
     * @return array{online: bool, failed: bool}
     */
    public function connection(string $businessId): array
    {
        $this->calls[] = "connection {$businessId}";

        if ($this->silent) {
            throw new RuntimeException('The window did not answer.');
        }

        return ['online' => $this->online, 'failed' => ($this->windows[$businessId]['failed'] ?? null) !== null];
    }

    public function hide(string $businessId): void
    {
        $this->calls[] = "hide {$businessId}";

        if (isset($this->windows[$businessId])) {
            $this->windows[$businessId]['visible'] = false;
        }
    }

    public function retry(string $businessId): bool
    {
        $this->calls[] = "retry {$businessId}";
        $failed = $this->windows[$businessId]['failed'] ?? null;

        if ($failed === null || parse_url($failed, PHP_URL_PATH) === '/app/session') {
            return false;
        }

        $this->windows[$businessId] = ['failed' => null, 'visible' => true, 'open' => true];

        return true;
    }

    public function close(string $businessId): void
    {
        $this->calls[] = "close {$businessId}";
        unset($this->windows[$businessId]);
    }
}
