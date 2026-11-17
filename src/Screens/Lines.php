<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * Counted lines a screen shows: the devices in use, what an app adds at a hook place ("Releasing: 3 refused, 12
 * sending, a shift is open"; "Plan ended: 14 sales are safe on this terminal"). Each line is
 * `{text, count?, hint?, variant?, icon?}`; `variant` is a Bootstrap colour (`secondary` by default).
 */
final class Lines
{
    /**
     * Keep only lines with a text, in one shape.
     *
     * @param  array<mixed>  $lines
     * @return list<array{text: string, count: int|null, hint: string|null, variant: string, icon: string|null}>
     */
    public static function clean(array $lines): array
    {
        $clean = [];

        foreach ($lines as $line) {
            if (is_string($line) && $line !== '') {
                $line = ['text' => $line];
            }

            if (! is_array($line) || ! is_string($line['text'] ?? null) || $line['text'] === '') {
                continue;
            }

            $clean[] = [
                'text' => $line['text'],
                'count' => is_numeric($line['count'] ?? null) ? (int) $line['count'] : null,
                'hint' => is_string($line['hint'] ?? null) && $line['hint'] !== '' ? $line['hint'] : null,
                'variant' => is_string($line['variant'] ?? null) && $line['variant'] !== '' ? $line['variant'] : 'secondary',
                'icon' => is_string($line['icon'] ?? null) && $line['icon'] !== '' ? $line['icon'] : null,
            ];
        }

        return $clean;
    }
}
