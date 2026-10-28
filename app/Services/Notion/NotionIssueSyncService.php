<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled sync: watches the configured Notion database (Setting
 * "notion_watch_page_id") and imports new entries as Issue records
 * (classification = Issue). Each run reads only rows created at/after the
 * last processed row (Setting "notion_sync_watermark" — the raw createdTime
 * string of the newest row already seen), so Notion is never re-scanned in
 * full. Page-ID AND normalized-title dedupe remain as safety nets (first-run
 * overlap with the CSV backlog, rows sharing the watermark's second). Issues
 * deleted from the dashboard are re-imported by direct page lookup
 * (recoverDeletedIssues) — deleting from the dashboard is temporary;
 * permanent removal = delete the row in Notion.
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

    /**
     * Columns every row query reads — the database's own properties (same
     * names the CSV importer maps); date properties use the expanded
     * `date:<name>:start` column documented in the fetched schema.
     */
    protected const ROW_COLUMNS = 'url, createdTime, "Name", "date:Date of Incident:start" AS date_start, '
        .'"Classification by Tech Risk Incident" AS classification, "Root Cause Classification" AS root_cause';

    public function __construct(private NotionMcpClient $notion) {}

    /**
     * @return array{status: string, message: string, created: int, skipped: int, preview: ?string}
     */
    public function sync(bool $dryRun = false): array
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
        $lines = [];

        // Restore semantics (owner 2026-10-28): deleting a Notion-synced
        // Issue from the dashboard is temporary — the next sync re-imports
        // it. Permanent removal = delete the row in Notion. Needs the
        // collection URL, hence after the fetch. Dry-run skips this: it
        // mutates state by definition.
        if (! $dryRun) {
            $recovered = $this->recoverDeletedIssues($collectionUrl, $lines);
            $created += $recovered['created'];
            $skipped += $recovered['skipped'];
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
                }

                continue;
            }

            if ($this->importRow($row, $pageId, $dryRun, $lines) !== null) {
                $created++;
            } elseif (! $boundary) {
                $skipped++;
            }
        }

        $label = $dryRun ? 'Would import' : 'Imported';

        if (! $dryRun) {
            $this->advanceWatermark($rows);
        }

        $held = $skipped > 0 ? "; {$skipped} already synced/duplicate/blank" : '';
        $message = ($created === 0 && $skipped === 0)
            ? 'No new issues.'
            : "{$label} {$created} issue(s){$held}.";

        return $this->finish('ok', $message, $created, $skipped, $dryRun, implode("\n", $lines));
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

        if (blank($title) || NotionIssueMapper::findDuplicateByTitle((string) $title) !== null) {
            return null;
        }

        $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

        // The database's own severity column wins; the root-cause map is
        // the fallback for rows where nobody classified it yet.
        $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
            ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

        $lines[] = "- {$title} ({$incidentDate->toDateString()}, {$severity})";

        if ($dryRun) {
            return new Incident;
        }

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
     *
     * @param  array<int, string>  $lines
     * @return array{created: int, skipped: int}
     */
    protected function recoverDeletedIssues(string $collectionUrl, array &$lines): array
    {
        $dangling = NotionSyncedPage::query()->whereNull('incident_id')->get();

        $created = 0;
        $skipped = 0;

        foreach ($dangling as $page) {
            $row = $this->queryPageById($collectionUrl, $page->page_id);

            if ($row === null) {
                continue;
            }

            if ($row === []) {
                Log::info('Notion issue sync: page no longer in Notion, dropping the tombstone.', ['page_id' => $page->page_id]);
                $page->delete();

                continue;
            }

            // Free the page ID first — importRow re-registers it.
            NotionSyncedPage::where('page_id', $page->page_id)->whereNull('incident_id')->delete();

            if ($this->importRow($row, $page->page_id, false, $lines) !== null) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
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
     * were handled) only after a fully successful run; a mid-run failure
     * leaves it, so the next run re-reads and dedupe skips what already
     * landed.
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
