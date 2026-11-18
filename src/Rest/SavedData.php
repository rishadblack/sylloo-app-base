<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The saved-data frame (plan §8.5, §7.7): "keep these lists on the device and refresh what changed". An app names its
 * lists (config `sylloo-app-base.rest.saved.lists`, or the names it passes); {@see self::refresh()} reads a list from
 * the REST door with "changed since" the last read, page by page while `has_more`, keeps every row by its `id`, removes
 * the rows the door says were removed, and remembers the first page's `server_time` as the next mark and when it was
 * saved ("Saved on 17 Nov, 09:12"). Offline the
 * rows stay as they were. Rows are kept encrypted with the app key; one frame per activation (`scope`).
 */
class SavedData
{
    /** A list is read at most this many pages in one refresh (a guard against a door that always has more). */
    public const MAX_PAGES = 1000;

    public function __construct(protected string $scope = 'default') {}

    /**
     * The lists the app keeps.
     *
     * @return list<string>
     */
    public static function lists(): array
    {
        return array_values((array) config('sylloo-app-base.rest.saved.lists', []));
    }

    /**
     * Refresh one list. Returns the last answer (ok when the list is now up to date).
     *
     * @param  array<string, scalar|null>  $filters
     */
    public function refresh(RestClient $client, string $list, array $filters = []): RestResult
    {
        $since = $this->since($list);
        $page = 1;
        $mark = null;

        do {
            $result = $client->list($list, $since, $page, $filters);

            if (! $result->ok()) {
                return $result;
            }

            foreach ($result->rows() as $row) {
                $id = RestWire::rowId($row);

                if ($id !== null) {
                    $this->rowsTable()->updateOrInsert(
                        ['scope' => $this->scope, 'list' => $list, 'row_id' => $id],
                        ['data' => Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR))],
                    );
                }
            }

            $removed = $result->removed();

            // rowsTable() is this activation's only (`where scope`): a row removed here stays in another business.
            if ($removed !== []) {
                $this->rowsTable()->where('list', $list)->whereIn('row_id', $removed)->delete();
            }

            // The first page's time is the next mark: a row changed while the later pages were read comes again.
            $mark ??= $result->serverTime();
            $page++;
        } while ($result->hasMore() && $page <= self::MAX_PAGES);

        $this->listsTable()->updateOrInsert(
            ['scope' => $this->scope, 'list' => $list],
            ['since' => $mark ?? now()->toIso8601String(), 'saved_at' => now()],
        );

        return $result;
    }

    /**
     * Refresh every list the app keeps; true for each list that is now up to date.
     *
     * @param  list<string>|null  $lists
     * @return array<string, bool>
     */
    public function refreshAll(RestClient $client, ?array $lists = null): array
    {
        $done = [];

        foreach ($lists ?? self::lists() as $list) {
            $result = $this->refresh($client, $list);
            $done[$list] = $result->ok();

            if ($result->isUnreachable()) {
                break;
            }
        }

        return $done;
    }

    /**
     * Every kept row of a list.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $list): array
    {
        return $this->rowsTable()->where('list', $list)->orderBy('id')->pluck('data')
            ->map(fn ($data) => json_decode(Crypt::decryptString((string) $data), true))
            ->filter(fn ($row) => is_array($row))
            ->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $list, string $id): ?array
    {
        $data = $this->rowsTable()->where('list', $list)->where('row_id', $id)->value('data');
        $row = $data !== null ? json_decode(Crypt::decryptString((string) $data), true) : null;

        return is_array($row) ? $row : null;
    }

    public function since(string $list): ?string
    {
        $since = $this->listsTable()->where('list', $list)->value('since');

        return is_string($since) && $since !== '' ? $since : null;
    }

    public function savedAt(string $list): ?Carbon
    {
        $at = $this->listsTable()->where('list', $list)->value('saved_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /**
     * Forget one list, or everything of this activation (release, sign-out of the employee).
     */
    public function clear(?string $list = null): void
    {
        $this->rowsTable()->when($list !== null, fn (Builder $query) => $query->where('list', $list))->delete();
        $this->listsTable()->when($list !== null, fn (Builder $query) => $query->where('list', $list))->delete();
    }

    protected function rowsTable(): Builder
    {
        return DB::connection(config('sylloo-app-base.rest.connection'))
            ->table((string) config('sylloo-app-base.rest.saved.rows_table', 'sylloo_saved_rows'))
            ->where('scope', $this->scope);
    }

    protected function listsTable(): Builder
    {
        return DB::connection(config('sylloo-app-base.rest.connection'))
            ->table((string) config('sylloo-app-base.rest.saved.lists_table', 'sylloo_saved_lists'))
            ->where('scope', $this->scope);
    }
}
