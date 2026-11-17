<?php

namespace Rishadblack\SyllooAppBase\Testing;

use RuntimeException;

/**
 * The office's example answers, taken from the Bruno API collection (`sylloo-erp-api-collection/Employee mode`: the
 * first ```json block of each request's docs), kept in `office-examples.json` next to this class so tests run without
 * the collection. {@see self::fromCollection()} reads them again; the base's tests compare both when the collection is
 * there, so a changed example is noticed.
 *
 * Each example: `door` (`device`: the device door, its path after `/device/api`; `rest`: the REST door, its path after
 * `rest/v1/`, e.g. `reads/home`, `lists/attendance`, `actions/attendance/punch`, `uploads`; `business`: another
 * address of the business), `method`, `path` and `answer`. An example whose JSON does not read is left out.
 */
final class OfficeExamples
{
    public const FILE = __DIR__.'/office-examples.json';

    /** @var array<string, array{door: string, method: string, path: string, answer: array<string, mixed>}>|null */
    protected static ?array $examples = null;

    /**
     * Every example, by request name ("Sign-in Finish").
     *
     * @return array<string, array{door: string, method: string, path: string, answer: array<string, mixed>}>
     */
    public static function all(): array
    {
        if (self::$examples === null) {
            $examples = json_decode((string) file_get_contents(self::FILE), true);
            self::$examples = is_array($examples) ? $examples : [];
        }

        return self::$examples;
    }

    /**
     * One example answer, made usable: "…" in a text is removed, and placeholder hashes become null.
     *
     * @return array<string, mixed>
     */
    public static function answer(string $name): array
    {
        $example = self::all()[$name] ?? throw new RuntimeException("No office example named {$name}.");

        return self::clean($example['answer']);
    }

    /**
     * Read the examples from a collection folder (`…/sylloo-erp-api-collection/Employee mode`).
     *
     * @return array<string, array{door: string, method: string, path: string, answer: array<string, mixed>}>
     */
    public static function fromCollection(string $folder): array
    {
        $examples = [];
        $files = glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.yml') ?: [];
        sort($files);

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);

            if (preg_match('/^\s*name:\s*"?([^"\n]+)"?\s*$/m', $text, $name) !== 1
                || preg_match('/^\s*method:\s*(\w+)\s*$/m', $text, $method) !== 1
                || preg_match('/^\s*url:\s*"?(\{\{baseUrl\}\}|\{\{restUrl\}\}\/?|https:\/\/\{\{host\}\})([^"\s?]*)/m', $text, $path) !== 1
                || preg_match('/```json\s*\n(.*?)\n\s*```/s', $text, $json) !== 1) {
                continue;
            }

            $answer = json_decode($json[1], true);

            if (is_array($answer)) {
                $examples[trim($name[1])] = [
                    'door' => match (true) {
                        $path[1] === '{{baseUrl}}' => 'device',
                        str_starts_with($path[1], '{{restUrl}}') => 'rest',
                        default => 'business',
                    },
                    'method' => strtoupper($method[1]),
                    'path' => $path[2],
                    'answer' => $answer,
                ];
            }
        }

        return $examples;
    }

    /**
     * The example of a REST door call (`reads/home`, `lists/attendance`, `actions/attendance/punch`, `uploads`) and
     * method, made usable, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function restAnswer(string $path, string $method = 'GET'): ?array
    {
        foreach (self::all() as $example) {
            if ($example['door'] === 'rest' && $example['path'] === trim($path, '/') && $example['method'] === strtoupper($method)) {
                return self::clean($example['answer']);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected static function clean(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::clean($item);
            } elseif (is_string($item)) {
                $value[$key] = $key === 'pin_hash' ? null : str_replace('…', '', $item);
            }
        }

        return $value;
    }
}
