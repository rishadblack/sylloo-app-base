<?php

namespace Rishadblack\SyllooAppBase\Console;

/**
 * The file work of `php artisan sylloo-app-base:install`, against an app's folder (so it can be tested on a temporary
 * one): adds the base's style and script to the app's entry files between marker lines, once. Running it again
 * changes nothing; a file that is not there is made.
 */
final class Installer
{
    public const MARK_START = 'sylloo-app-base:start';

    public const MARK_END = 'sylloo-app-base:end';

    public function __construct(protected string $basePath) {}

    /**
     * Add the import lines. Returns, for each file, whether it changed.
     *
     * @return array<string, bool>
     */
    public function addImports(string $styleFile = 'resources/scss/app.scss', string $scriptFile = 'resources/js/app.js'): array
    {
        return [
            $styleFile => $this->addBlock($styleFile, '//', [
                "@import '../../vendor/rishadblack/sylloo-app-base/resources/scss/sylloo-app-base';",
            ]),
            $scriptFile => $this->addBlock($scriptFile, '//', [
                "import '../../vendor/rishadblack/sylloo-app-base/resources/js/sylloo-app-base.js';",
            ]),
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    public function addBlock(string $file, string $comment, array $lines): bool
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
        $current = is_file($path) ? (string) file_get_contents($path) : '';

        if (str_contains($current, self::MARK_START)) {
            return false;
        }

        $block = implode("\n", [
            "{$comment} ".self::MARK_START.' (added by php artisan sylloo-app-base:install)',
            ...$lines,
            "{$comment} ".self::MARK_END,
        ]);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, rtrim($current) === '' ? $block."\n" : rtrim($current)."\n\n".$block."\n");

        return true;
    }
}
