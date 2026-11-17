<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Carbon\CarbonInterface;

/**
 * One write made offline, waiting to be sent: the action, its fields, the files still to upload and its one-use key
 * (plan §8.5). `status` is `waiting` or `refused` (kept with the office's reason, never thrown away).
 */
final class WaitingWrite
{
    public const WAITING = 'waiting';

    public const REFUSED = 'refused';

    /**
     * @param  array<string, mixed>  $fields  with `<field>_file_id` for every file already uploaded
     * @param  array<string, string>  $files  the files still to upload: field => file name
     */
    public function __construct(
        public readonly int $id,
        public readonly string $action,
        public readonly array $fields,
        public readonly string $key,
        public readonly string $status,
        public readonly int $tries,
        public readonly ?string $code,
        public readonly ?string $reason,
        public readonly CarbonInterface $madeAt,
        public readonly array $files = [],
    ) {}

    public function isRefused(): bool
    {
        return $this->status === self::REFUSED;
    }
}
