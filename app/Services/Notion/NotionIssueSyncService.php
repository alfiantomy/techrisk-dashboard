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
 * (classification = Issue), deduped by Notion page ID AND normalized title
 * (titles already imported via the CSV importer must not be re-created).
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

    /** Newest-first scan window; page-ID dedupe makes re-scanning safe. */
    protected const SCAN_LIMIT = 200;

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

        $query = $this->notion->callToolDetailed('notion-query-data-sources', [
            'data' => [
                'data_source_urls' => [$collectionUrl],
                // Column names are this database's properties (same names the
                // CSV importer maps); date properties use the expanded
                // `date:<name>:start` column documented in the fetched schema.
                'query' => sprintf(
                    'SELECT url, createdTime, "Name", "date:Date of Incident:start" AS date_start, '
                    .'"Classification by Tech Risk Incident" AS classification, "Root Cause Classification" AS root_cause '
                    .'FROM "%s" ORDER BY createdTime DESC LIMIT %d',
                    $collectionUrl,
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

        $created = 0;
        $skipped = 0;
        $lines = [];

        foreach ($rows as $row) {
            $pageId = $this->pageIdFromUrl((string) ($row['url'] ?? ''));

            if ($pageId === null || NotionSyncedPage::where('page_id', $pageId)->exists()) {
                $skipped++;

                continue;
            }

            $title = $row['Name'] ?? null;

            if (blank($title) || NotionIssueMapper::findDuplicateByTitle((string) $title) !== null) {
                $skipped++;

                continue;
            }

            $incidentDate = $this->parseDate($row['date_start'] ?? null, $row['createdTime'] ?? null);

            // The database's own severity column wins; the root-cause map is
            // the fallback for rows where nobody classified it yet.
            $severity = NotionIssueMapper::mapClassificationToSeverity($row['classification'] ?? null)
                ?? NotionIssueMapper::mapSeverityToCode((string) ($row['root_cause'] ?? 'G'));

            $lines[] = "- {$title} ({$incidentDate->toDateString()}, {$severity})";

            if ($dryRun) {
                $created++;

                continue;
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
            $created++;
        }

        $label = $dryRun ? 'Would import' : 'Imported';

        return $this->finish('ok', "{$label} {$created} issue(s); {$skipped} already synced/duplicate/blank.", $created, $skipped, $dryRun, implode("\n", $lines));
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
