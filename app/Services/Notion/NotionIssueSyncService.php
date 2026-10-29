<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled sync: watches the configured Notion database (Setting
 * "notion_watch_page_id") and imports new entries as Issue records
 * (classification = Issue). Each run reads only rows created at/after the
 * last processed row (Setting "notion_sync_watermark" — the raw createdTime
 * string of the newest row already seen), so Notion is never re-scanned in
 * full. Page-ID AND normalized-title dedupe remain as safety nets (first-run
 * overlap with the manual backlog, rows sharing the watermark's second).
 *
 * Deleting an imported Issue from the dashboard is PERMANENT for the normal
 * sync (owner 2026-10-29) — tombstones are only revisited by an explicit
 * full sync (restoreDeleted: true, the "Full sync" checkbox), which
 * re-imports them by direct page lookup. Permanent removal is still deleting
 * the row in Notion; a full sync drops the tombstone of a page that no
 * longer exists there.
 *
 * Live shape (verified 2026-10-27 against mcp.notion.com): `notion-fetch` on
 * a database returns its SCHEMA, not rows — including the queryable
 * `collection://<uuid>` table name. Rows come from `notion-query-data-sources`
 * with the arguments wrapped in a `data` object:
 *   {"data": {"data_source_urls": ["collection://..."], "query": "SELECT ..."}}
 * returning {"results": [{url, createdTime, Name, "date:Date of Incident:start",
 * "Classification by Tech Risk Incident", "Root Cause Classification"}, ...]}.
 */
class NotionIssueSyncService
{
    public const WATCH_KEY = 'notion_watch_page_id';

    public const LAST_SYNC_AT_KEY = 'notion_last_sync_at';

    public const LAST_SYNC_STATUS_KEY = 'notion_last_sync_status';

    public const WATERMARK_KEY = 'notion_sync_watermark';

    /** Per-run cap on new rows; ASC order + watermark picks the rest up next run. */
    protected const SCAN_LIMIT = 200;

    /** Normalized titles of existing Issues, loaded once per run — never a per-row table scan. */
    protected ?array $existingIssueTitles = null;

    /**
     * Columns every row query reads — the database's own properties (same
     * names the CSV importer maps); date properties use the expanded
     * `date:<name>:start` column documented in the fetched schema.
     */
    protected const ROW_COLUMNS = 'url, createdTime, "Name", "date:Date of Incident:start" AS date_start, '
        .'"Classification by Tech Risk Incident" AS classification, "Root Cause Classification" AS root_cause';

    public function __construct(private NotionMcpClient $notion) {}

    /**
     * @param  bool  $restoreDeleted  Full sync — also re-import Issues deleted
     *                                from the dashboard (their tombstones).
     * @return array{status: string, message: string, created: int, skipped: int, preview: ?string}
     */
    public function sync(bool $dryRun = false, bool $restoreDeleted = false): array
    {
        $targetId = Setting::get(self::WATCH_KEY);

        if (blank($targetId)) {
            return $this->finish('failed', 'No Notion watch target configured — set it on the Notion Settings page.', 0, 0, $dryRun);
        }

        if (! $this->notion->connected()) {
            return $this->finish('failed', 'Notion is not connected — connect it on the Notion Settings page.', 0, 0, $dryRun);
        }

        $fetch = $this->notion->callToolDetailed('notion-fetch', ['id' => $targetId]);

        if ($fetch === null) {
            return $this->finish('failed', 'Notion fetch failed — check the logs.', 0, 0, $dryRun);
        }

        if ($fetch['isError']) {
            return $this->finish('failed', 'Notion fetch failed: '.$fetch['text'], 0, 0, $dryRun, $fetch['text']);
        }

        $collectionUrl = $this->extractCollectionUrl($fetch['text']);

        if ($collectionUrl === null) {
            return $this->finish('failed', 'Watch target is not a Notion database — set the database page ID on the Notion Settings page.', 0, 0, $dryRun, $fetch['text']);
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $lines = [];
        // createdTime of the FIRST failed row — the watermark never advances
        // past it, so the row is retried once its data is fixed (everything
        // after it was already imported and is absorbed by page-ID dedupe).
        $firstFailureTime = null;

        // Restore semantics (owner 2026-10-29): tombstones are only revisited
        // by an explicit full sync — the normal sync (and the scheduler) never
        // brings a deleted Issue back. Needs the collection URL, hence after
        // the fetch. In dry-run this reads Notion only and reports what a full
        // sync would restore.
        if ($restoreDeleted) {
            $recovered = $this->recoverDeletedIssues($collectionUrl, $lines, $dryRun);
            $created += $recovered['created'];
            $skipped += $recovered['skipped'];
            $failed += $recovered['failed'];
        }

        $query = $this->notion->callToolDetailed('notion-query-data-sources', [
            'data' => [
                'data_source_urls' => [$collectionUrl],
                // Column names are this database's properties (same names the
                // CSV importer maps); date properties use the expanded
                // `date:<name>:start` column documented in the fetched schema.
                //
                // Incremental: only rows created at/after the watermark are
                // read — `>=` (not >) so a row sharing the watermark's second
                // is still fetched and page-ID dedupe absorbs the re-read.
                // ASC + LIMIT means a burst of new rows is drained oldest
                // first, the remainder next run (DESC could skip middles).
                'query' => sprintf(
                    'SELECT %s FROM "%s" %sORDER BY createdTime ASC LIMIT %d',
                    self::ROW_COLUMNS,
                    $collectionUrl,
                    $this->whereSinceWatermark(),
                    self::SCAN_LIMIT,
                ),
            ],
        ]);

        if ($query === null) {
            return $this->finish('failed', 'Notion data-source query failed — check the logs.', 0, 0, $dryRun);
        }

        if ($query['isError']) {
            return $this->finish('failed', 'Notion data-source query failed: '.$query['text'], 0, 0, $dryRun, $query['text']);
        }

        $rows = $this->extractRows($query['text']);

        if ($rows === null) {
            return $this->finish('failed', 'Could not read rows from the Notion query response — see the preview for the raw shape.', 0, 0, $dryRun, $query['text']);
        }

        // The `>=` window always re-fetches rows sharing the watermark's
        // second; ones already handled last run are expected re-reads, not
        // findings — skipped but never counted.
        $watermark = (string) Setting::get(self::WATERMARK_KEY);

        foreach ($rows as $row) {
            $boundary = ((string) ($row['createdTime'] ?? '')) === $watermark;
            $pageId = $this->pageIdFromUrl((string) ($row['url'] ?? ''));

            if ($pageId === null || NotionSyncedPage::where('page_id', $pageId)->exists()) {
                if (! $boundary) {
                    $skipped++;
                    // Itemized for the dry-run preview (a real sync shows it
                    // only when the run fails). The boundary re-read is an
                    // expected re-read, never a finding.
                    $lines[] = $pageId === null
                        ? '- Unreadable page URL: '.($row['Name'] ?? '?')
                        : '- Already synced from Notion: '.($row['Name'] ?? '?');
                }

                continue;
            }

            // Per-row isolation: one throwing row (e.g. a title longer than
            // incidents.title under MySQL strict mode) must not wedge the
            // unattended cron — report it and keep importing the rest.
            try {
                $imported = $this->importRow($row, $pageId, $dryRun, $lines);
            } catch (\Throwable $e) {
                $failed++;
                $firstFailureTime ??= (string) ($row['createdTime'] ?? '');

                Log::warning('Notion issue sync: row import failed.', [
                    'page_id' => $pageId,
                    'title' => $row['Name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $lines[] = 'FAILED: '.($row['Name'] ?? $pageId).' — '.$e->getMessage();

                continue;
            }

            if ($imported !== null) {
                $created++;
            } elseif (! $boundary) {
                $skipped++;
            }
        }

        $label = $dryRun ? 'Would import' : 'Imported';

        if (! $dryRun) {
            $rowsForWatermark = $firstFailureTime === null
                ? $rows
                : array_values(array_filter($rows, fn (array $candidate): bool => (string) ($candidate['createdTime'] ?? '') < $firstFailureTime));
            $this->advanceWatermark($rowsForWatermark);
        }

        $held = $skipped > 0 ? "; {$skipped} already synced/duplicate/blank" : '';
        $broken = $failed > 0 ? "; {$failed} failed" : '';
        $message = ($created === 0 && $skipped === 0 && $failed === 0)
            ? 'No new issues.'
            : "{$label} {$created} issue(s){$held}{$broken}.";

        return $this->finish($failed > 0 ? 'partial' : 'ok', $message, $created, $skipped, $dryRun, implode("\n", $lines));
    }

    /**
     * Map one Notion row to an Issue and (unless dry-run) create it with its
     * synced-page row. Null when the row is blank or a duplicate title —
     * the caller decides whether that counts as skipped. Shared by the main
     * window loop and deleted-issue recovery so the two cannot drift.
     */
    protected function importRow(array $row, string $pageId, bool $dryRun, array &$lines): ?Incident
    {
        $title = $row['Name'] ?? null;

        if (blank($title)) {
            $lines[] = '- Blank title: '.$pageId;

            return null;
        }

        if ($this->titleAlreadyExists((string) $title)) {
            $lines[] = '- Duplicate title (already in the dashboard): '.$title;

            return null;
        }

        $lines[] = '- '.$this->describeRow($row);
        // This row is being imported — later rows with the same title must
        // dedupe against it (the per-row DB lookup used to see it; the set must too).
        $this->existingIssueTitles[\App\Helpers\StringHelper::normalizeForComparison((string) $title)] = true;

        if ($dryRun) {
            return new Incident;
        }

        $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

        // The database's own severity column wins; the root-cause map is
        // the fallback for rows where nobody classified it yet.
        $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
            ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

        // Atomic pair: a failed synced-page insert (unique page_id — the
        // manual sync racing the cron) must not leave an orphan Incident.
        $incident = DB::transaction(function () use ($title, $incidentDate, $severity, $pageId): Incident {
            $incident = Incident::create([
                'title' => NotionIssueMapper::cleanTitle((string) $title),
                'incident_date' => $incidentDate,
                'entry_date_tech_risk' => $incidentDate->format('Y-m-d'),
                'severity' => $severity,
                'classification' => IncidentClassification::Issue->value,
                // NOT NULL "Area"/"Source" enums — the CSV importer leaves
                // them unset (it only fills incident_type_id); Notion issues
                // are internally tracked tech issues.
                'incident_type' => 'Tech',
                'incident_source' => 'Internal',
                'incident_type_id' => IncidentType::first()?->id,
                'no' => NotionIssueMapper::generateIssueId(),
            ]);

            NotionSyncedPage::create(['page_id' => $pageId, 'incident_id' => $incident->id]);

            return $incident;
        });

        return $incident;
    }

    /**
     * Duplicate check against the once-per-run title set. New imports join
     * the set, so two identical rows inside one run still dedupe (same
     * semantics the per-row lookup had). Per-instance, never static —
     * long-lived FPM workers must not pin a run's set.
     */
    protected function titleAlreadyExists(string $title): bool
    {
        $this->existingIssueTitles ??= NotionIssueMapper::existingNormalizedTitles();

        return isset($this->existingIssueTitles[\App\Helpers\StringHelper::normalizeForComparison($title)]);
    }

    /**
     * One line's human summary: "{title} ({date}, {severity})" — the shared
     * body of the would-import and would-restore preview lines.
     */
    protected function describeRow(array $row): string
    {
        $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

        // The database's own severity column wins; the root-cause map is
        // the fallback for rows where nobody classified it yet.
        $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
            ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

        return ($row['Name'] ?? '?')." ({$incidentDate->toDateString()}, {$severity})";
    }

    /**
     * Re-import Issues deleted from the dashboard. Their synced-page rows
     * dangle (incident_id NULL via the FK's nullOnDelete). Each dangling
     * page is fetched from Notion DIRECTLY by page ID — no watermark
     * rewind, so nothing else is re-read and the incremental window is
     * untouched. A page no longer in Notion loses its tombstone (permanent
     * removal = delete the row in Notion); a failed lookup keeps it for the
     * next run. A page whose title already exists skips (restoring would
     * duplicate) and loses its tombstone so it is not retried every run.
     * The tombstone delete + re-import run in ONE transaction — a throwing
     * import rolls the delete back, so the restore promise never dies.
     *
     * Dry-run (the Preview): reads only — nothing is deleted or imported;
     * each restorable page is reported as a would-restore line.
     *
     * @param  array<int, string>  $lines
     * @return array{created: int, skipped: int, failed: int}
     */
    protected function recoverDeletedIssues(string $collectionUrl, array &$lines, bool $dryRun): array
    {
        $dangling = NotionSyncedPage::query()->whereNull('incident_id')->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($dangling as $page) {
            $row = $this->queryPageById($collectionUrl, $page->page_id);

            if ($row === null) {
                continue;
            }

            if ($dryRun) {
                // Preview-only: report what a full sync would bring back.
                $title = $row['Name'] ?? null;

                if (blank($title) || $this->titleAlreadyExists((string) $title)) {
                    $skipped++;

                    continue;
                }

                $lines[] = '- Would restore (deleted issue): '.$this->describeRow($row);
                $created++;

                continue;
            }

            if ($row === []) {
                Log::info('Notion issue sync: page no longer in Notion, dropping the tombstone.', ['page_id' => $page->page_id]);
                $page->delete();

                continue;
            }

            try {
                $restored = DB::transaction(function () use ($page, $row, &$lines): bool {
                    // Free the page ID first — importRow re-registers it.
                    NotionSyncedPage::where('page_id', $page->page_id)->whereNull('incident_id')->delete();

                    return $this->importRow($row, $page->page_id, false, $lines) !== null;
                });
            } catch (\Throwable $e) {
                $failed++;

                Log::warning('Notion issue sync: deleted-page re-import failed.', [
                    'page_id' => $page->page_id,
                    'error' => $e->getMessage(),
                ]);
                $lines[] = 'FAILED: '.($row['Name'] ?? $page->page_id).' — '.$e->getMessage();

                continue;
            }

            if ($restored) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * One page's row from the watched database, looked up by page ID.
     * Returns the row, [] when the page no longer exists, or null when the
     * lookup failed (the caller keeps the tombstone and retries next run).
     *
     * @return array<string, mixed>|array<empty, empty>|null
     */
    protected function queryPageById(string $collectionUrl, string $pageId): ?array
    {
        // $pageId is 32-hex (pageIdFromUrl) — no quoting or injection concerns.
        if (! ctype_xdigit($pageId)) {
            return null;
        }

        $query = $this->notion->callToolDetailed('notion-query-data-sources', [
            'data' => [
                'data_source_urls' => [$collectionUrl],
                'query' => sprintf(
                    'SELECT %s FROM "%s" WHERE url LIKE \'%%%s%%\'',
                    self::ROW_COLUMNS,
                    $collectionUrl,
                    $pageId,
                ),
            ],
        ]);

        if ($query === null || $query['isError']) {
            Log::warning('Notion issue sync: deleted-page lookup failed.', [
                'page_id' => $pageId,
                'text' => $query['text'] ?? null,
            ]);

            return null;
        }

        $rows = $this->extractRows((string) $query['text']);

        return $rows === null ? null : ($rows[0] ?? []);
    }

    /**
     * WHERE clause for the incremental query — empty on the first run (full
     * backfill), else everything created at/after the last processed row.
     * The watermark is Notion's own createdTime string, passed through
     * unmodified so the comparison format always matches the column's.
     */
    protected function whereSinceWatermark(): string
    {
        // No default argument — Setting::get caches its default via
        // Cache::remember, and a cached '' would mask a later-set value.
        $watermark = (string) Setting::get(self::WATERMARK_KEY);

        if (blank($watermark)) {
            return '';
        }

        return sprintf("WHERE createdTime >= '%s' ", str_replace("'", "''", $watermark));
    }

    /**
     * Move the watermark to the newest row SEEN (created or skipped — both
     * were handled). Called with the rows BEFORE the first failure, so when
     * any row failed the watermark stops there: everything from that row on
     * is re-read next run — page-ID dedupe absorbs what already landed and
     * the failed row retries once its data is fixed.
     */
    protected function advanceWatermark(array $rows): void
    {
        $newest = '';

        foreach ($rows as $row) {
            $createdTime = (string) ($row['createdTime'] ?? '');

            if ($createdTime > $newest) {
                $newest = $createdTime;
            }
        }

        if ($newest !== '') {
            Setting::set(self::WATERMARK_KEY, $newest);
        }
    }

    /**
     * The queryable table name, from the fetched data-source document
     * (`<data-source url="{{collection://uuid}}">` / `"url":"collection://uuid"`).
     */
    protected function extractCollectionUrl(string $text): ?string
    {
        return preg_match('/collection:\/\/[0-9a-f-]{36}/i', $text, $m) ? $m[0] : null;
    }

    /**
     * @return list<array<string, mixed>>|null null when the response is not parseable JSON
     */
    protected function extractRows(string $text): ?array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            return null;
        }

        return array_values(array_filter($decoded['results'], fn ($row) => is_array($row)));
    }

    /** The trailing 32-hex ID of a Notion page URL. */
    protected function pageIdFromUrl(string $url): ?string
    {
        return preg_match('/([a-f0-9]{32})/i', $url, $m) ? $m[1] : null;
    }

    protected function parseDate(mixed $dateStart, mixed $createdTime): Carbon
    {
        foreach ([$dateStart, $createdTime] as $candidate) {
            if (filled($candidate)) {
                try {
                    return Carbon::parse((string) $candidate)->startOfDay();
                } catch (\Throwable) {
                }
            }
        }

        return Carbon::now()->startOfDay();
    }

    protected function finish(string $status, string $message, int $created, int $skipped, bool $dryRun, ?string $preview = null): array
    {
        if (! $dryRun) {
            Setting::set(self::LAST_SYNC_AT_KEY, now()->toIso8601String());
            Setting::set(self::LAST_SYNC_STATUS_KEY, $status.': '.$message);
        }

        if ($status === 'failed') {
            Log::warning('Notion issue sync: '.$message);
        }

        return ['status' => $status, 'message' => $message, 'created' => $created, 'skipped' => $skipped, 'preview' => $preview];
    }
}
