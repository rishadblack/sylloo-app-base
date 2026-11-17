<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * The one waiting list for writes made offline (plan §8.5, §7.7): each kept as "action, fields, files, one-use key",
 * sent later **in order** (oldest first) by {@see self::send()}, each once (the office does a key once and answers a
 * second send with the first answer, `X-Rest-Replayed`). A write's files (a selfie, a receipt) are uploaded first
 * (`rest/v1/uploads`, each with its own key made from the write's), and each id is put in the write's fields as
 * `<field>_file_id` before the action is sent.
 *
 * - The office refused a write (`refused`, `validation_failed`, `key_reused`, `no_permission`, …): kept with its
 *   reason ({@see WaitingWrite::REFUSED}), never thrown away; the app shows it and the person resolves it.
 * - The office is not reached, still works on the first send (`call_in_progress`), or has too much at once
 *   (`too_many_requests`): nothing more is sent now and the order stays.
 * - The door's gate refused the device or the person (`session_expired`, `device_released`, `app_too_old`, …): the
 *   writes keep waiting and {@see SendReport::$stoppedBy} names the code, for the app to deal with.
 *
 * An app only names which actions may wait: config `sylloo-app-base.rest.waiting.actions`, or {@see self::allow()}.
 * One list per activation (`scope` = the entry id). The fields and the files are kept encrypted with the app key.
 */
class WaitingList
{
    /** @var list<string> */
    protected static array $allowed = [];

    public function __construct(protected string $scope = 'default') {}

    /**
     * Let these actions (`<area>/<name>`) wait when made offline.
     */
    public static function allow(string ...$actions): void
    {
        static::$allowed = array_values(array_unique([...static::$allowed, ...$actions]));
    }

    /**
     * Forget the actions allowed in code (tests).
     */
    public static function forgetAllowed(): void
    {
        static::$allowed = [];
    }

    public static function mayWait(string $action): bool
    {
        return in_array($action, [...(array) config('sylloo-app-base.rest.waiting.actions', []), ...static::$allowed], true);
    }

    /**
     * Keep a write to send later. The key is the one-use key the office reads (a new one when none is given). Files
     * are named by their field: `['selfie' => $path]` is uploaded first and sent as `selfie_file_id`; a copy is kept
     * (encrypted), so the app may delete its own.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $files  field => path of a local file
     */
    public function add(string $action, array $fields, ?string $key = null, array $files = []): WaitingWrite
    {
        if (! RestWire::isActionName($action) || ! self::mayWait($action)) {
            throw new InvalidArgumentException("The action {$action} may not wait; name it in sylloo-app-base.rest.waiting.actions.");
        }

        $key ??= RestWire::newKey();

        if (! RestWire::isKey($key)) {
            throw new InvalidArgumentException('Not a one-use key: 8–64 of A-Z a-z 0-9 _ - : . are needed.');
        }

        foreach ($files as $field => $path) {
            if (preg_match('/^[a-z0-9_]+$/', (string) $field) !== 1 || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException("The file {$field} can not wait: name it in lowercase letters and _, and give a readable file.");
            }
        }

        $kept = [];

        foreach ($files as $field => $path) {
            $stored = $this->folder($key).'/'.$field;
            $this->disk()->put($stored, Crypt::encryptString((string) file_get_contents($path)));
            $kept[$field] = ['path' => $stored, 'name' => basename($path)];
        }

        $now = now();
        $id = $this->table()->insertGetId([
            'scope' => $this->scope,
            'action' => $action,
            'fields' => $this->encrypt($fields),
            'files' => $kept !== [] ? $this->encrypt($kept) : null,
            'key' => $key,
            'status' => WaitingWrite::WAITING,
            'tries' => 0,
            'made_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find((int) $id) ?? throw new InvalidArgumentException('The write was not kept.');
    }

    /**
     * How many writes wait (the "3 waiting" mark).
     */
    public function count(): int
    {
        return $this->table()->where('status', WaitingWrite::WAITING)->count();
    }

    public function refusedCount(): int
    {
        return $this->table()->where('status', WaitingWrite::REFUSED)->count();
    }

    /**
     * @return list<WaitingWrite>
     */
    public function waiting(): array
    {
        return $this->read($this->table()->where('status', WaitingWrite::WAITING));
    }

    /**
     * @return list<WaitingWrite>
     */
    public function refused(): array
    {
        return $this->read($this->table()->where('status', WaitingWrite::REFUSED));
    }

    public function find(int $id): ?WaitingWrite
    {
        return $this->read($this->table()->where('id', $id))[0] ?? null;
    }

    /**
     * Send what waits, oldest first: each write's files, then the action. Stops (keeping the order) when the office
     * can not be reached, asks to wait, the gate refuses, or something unexpected is answered (tried again later, at
     * most `max_tries` times, then kept as refused).
     */
    public function send(RestClient $client): SendReport
    {
        $sent = 0;
        $refused = 0;
        $unreachable = false;
        $stoppedBy = null;
        $maxTries = max(1, (int) config('sylloo-app-base.rest.waiting.max_tries', 10));

        foreach ($this->waiting() as $write) {
            $row = $this->table()->where('id', $write->id)->first(['next_try_at']);

            if ($row !== null && $row->next_try_at !== null && Carbon::parse($row->next_try_at)->isFuture()) {
                break;
            }

            $fields = $write->fields;
            $result = $this->uploadFiles($client, $write, $fields) ?? $client->run($write->action, $fields, $write->key);

            if ($result->ok()) {
                $this->forget($write->id);
                $sent++;

                continue;
            }

            if ($result->isUnreachable()) {
                $unreachable = true;

                break;
            }

            if ($result->triesLater()) {
                $this->tryLater($write->id, $result->retryAfter ?? 30);

                break;
            }

            if ($result->isGate()) {
                $stoppedBy = $result->code() ?? (string) $result->status;

                break;
            }

            if ($result->isRefused()) {
                $this->markRefused($write->id, $result->code(), $result->text());
                $refused++;

                continue;
            }

            $tries = $write->tries + 1;

            if ($tries >= $maxTries) {
                $this->markRefused($write->id, $result->code(), $result->text());
                $refused++;

                continue;
            }

            $this->table()->where('id', $write->id)->update(['tries' => $tries, 'updated_at' => now()]);
            $this->tryLater($write->id, min(3600, 2 ** $tries * 15));

            break;
        }

        return new SendReport($sent, $refused, $this->count(), $unreachable, $stoppedBy);
    }

    /**
     * Put a refused write back in the line (the person fixed what the office refused, or wants it tried again).
     */
    public function retry(int $id): void
    {
        $this->table()->where('id', $id)->update([
            'status' => WaitingWrite::WAITING, 'tries' => 0, 'code' => null, 'reason' => null, 'next_try_at' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * Remove one write and its kept files (sent, or the person dropped a refused one).
     */
    public function forget(int $id): void
    {
        $key = $this->table()->where('id', $id)->value('key');

        if (is_string($key)) {
            $this->disk()->deleteDirectory($this->folder($key));
        }

        $this->table()->where('id', $id)->delete();
    }

    /**
     * Remove every write of this activation and its files (the release clean-up, after what waits was sent).
     */
    public function clear(): void
    {
        $this->disk()->deleteDirectory($this->scopeFolder());
        $this->table()->delete();
    }

    /**
     * Upload the files the write still has, each id into its fields as `<field>_file_id` (kept at once, so a later
     * send does not upload it again). Null when every file is up; else the answer that stopped it.
     *
     * @param  array<string, mixed>  $fields  the write's fields, given each file id
     */
    protected function uploadFiles(RestClient $client, WaitingWrite $write, array &$fields): ?RestResult
    {
        if ($write->files === []) {
            return null;
        }

        $files = $this->filesOf($write->id);

        foreach ($files as $field => $file) {
            if (! $this->disk()->exists($file['path'])) {
                return new RestResult(OfficeOutcome::Refused, ['code' => 'file_missing', 'message' => Texts::get('waiting_file_missing')]);
            }

            $result = $client->uploadContents(Crypt::decryptString((string) $this->disk()->get($file['path'])), $file['name'], RestWire::fileKey($write->key, $field));

            if (! $result->ok()) {
                return $result;
            }

            if ($result->fileId() === null) {
                return new RestResult(OfficeOutcome::Unexpected, $result->answer, $result->status);
            }

            $fields[$field.'_file_id'] = $result->fileId();
            unset($files[$field]);

            $this->table()->where('id', $write->id)->update([
                'fields' => $this->encrypt($fields),
                'files' => $files !== [] ? $this->encrypt($files) : null,
                'updated_at' => now(),
            ]);
            $this->disk()->delete($file['path']);
        }

        return null;
    }

    protected function tryLater(int $id, int $seconds): void
    {
        $this->table()->where('id', $id)->update(['next_try_at' => now()->addSeconds($seconds), 'updated_at' => now()]);
    }

    protected function markRefused(int $id, ?string $code, string $reason): void
    {
        $this->table()->where('id', $id)->update([
            'status' => WaitingWrite::REFUSED, 'code' => $code, 'reason' => mb_substr($reason, 0, 1000), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    protected function filesOf(int $id): array
    {
        $files = $this->table()->where('id', $id)->value('files');

        return $files !== null ? $this->filesFrom((string) $files) : [];
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    protected function filesFrom(string $encrypted): array
    {
        $files = json_decode(Crypt::decryptString($encrypted), true);

        return collect(is_array($files) ? $files : [])
            ->filter(fn ($file) => is_array($file) && is_string($file['path'] ?? null) && is_string($file['name'] ?? null))
            ->map(fn (array $file) => ['path' => $file['path'], 'name' => $file['name']])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function encrypt(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<WaitingWrite>
     */
    protected function read(Builder $query): array
    {
        return $query->orderBy('id')->get()->map(function (object $row): WaitingWrite {
            $fields = json_decode(Crypt::decryptString((string) $row->fields), true);

            return new WaitingWrite(
                (int) $row->id,
                (string) $row->action,
                is_array($fields) ? $fields : [],
                (string) $row->key,
                (string) $row->status,
                (int) $row->tries,
                $row->code !== null ? (string) $row->code : null,
                $row->reason !== null ? (string) $row->reason : null,
                Carbon::parse($row->made_at),
                array_map(fn (array $file) => $file['name'], $row->files !== null ? $this->filesFrom((string) $row->files) : []),
            );
        })->values()->all();
    }

    protected function folder(string $key): string
    {
        return $this->scopeFolder().'/'.substr(hash('sha256', $key), 0, 32);
    }

    protected function scopeFolder(): string
    {
        return trim((string) config('sylloo-app-base.rest.waiting.folder', 'sylloo-waiting'), '/').'/'.substr(hash('sha256', $this->scope), 0, 16);
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('sylloo-app-base.rest.waiting.disk', 'local'));
    }

    protected function table(): Builder
    {
        return DB::connection(config('sylloo-app-base.rest.connection'))
            ->table((string) config('sylloo-app-base.rest.waiting.table', 'sylloo_waiting_writes'))
            ->where('scope', $this->scope);
    }
}
