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
 * Scheduled sync: watches the configured Notion page/database (Setting
 * "notion_watch_page_id") and imports new sub-pages as Issue records
 * (classification = Issue), deduped by Notion page ID.
 *
 * Entry/property shapes come from Notion's hosted MCP server and were not
 * fully verifiable offline — `notion:sync-issues --dry-run` prints what the
 * server actually returns so the extraction can be adjusted in one place
 * (extractEntries()/propertyValue()).
 */
class NotionIssueSyncService
{
    public const WATCH_KEY = 'notion_watch_page_id';

    public const LAST_SYNC_AT_KEY = 'notion_last_sync_at';

    public const LAST_SYNC_STATUS_KEY = 'notion_last_sync_status';

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

        $response = $this->notion->callToolDetailed('notion-fetch', ['id' => $targetId]);

        if ($response === null) {
            return $this->finish('failed', 'Notion fetch failed — check the logs.', 0, 0, $dryRun);
        }

        $entries = $this->extractEntries($response['structured'], $response['text']);

        if ($entries === []) {
            return $this->finish('failed', 'Could not read entries from the Notion fetch response — see the preview for the raw shape.', 0, 0, $dryRun, $response['text'] ?: null);
        }

        $created = 0;
        $skipped = 0;
        $lines = [];

        foreach ($entries as $entry) {
            $pageId = $entry['id'] ?? $entry['page_id'] ?? null;

            if (blank($pageId) || NotionSyncedPage::where('page_id', $pageId)->exists()) {
                $skipped++;

                continue;
            }

            $title = NotionIssueMapper::propertyValue($entry['properties'] ?? [], 'Name')
                ?? $entry['title'] ?? null;

            if (blank($title)) {
                $skipped++;

                continue;
            }

            $dateValue = NotionIssueMapper::propertyValue($entry['properties'] ?? [], 'Date of Incident')
                ?? $entry['created_time'] ?? null;

            try {
                $incidentDate = $dateValue
                    ? NotionIssueMapper::parseNotionDate((string) $dateValue)
                    : Carbon::now()->startOfDay();
            } catch (\Throwable) {
                $incidentDate = Carbon::now()->startOfDay();
            }

            $severity = NotionIssueMapper::mapSeverityToCode(
                (string) (NotionIssueMapper::propertyValue($entry['properties'] ?? [], 'Root Cause Classification') ?? 'G'),
            );

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

        return $this->finish('ok', "{$label} {$created} issue(s); {$skipped} already synced/blank.", $created, $skipped, $dryRun, implode("\n", $lines));
    }

    /**
     * Pull page-like entries out of a notion-fetch response. Adjust HERE (one
     * place) once the live response shape is known — see --dry-run.
     *
     * @param  array<string, mixed>|null  $structured
     * @return list<array<string, mixed>>
     */
    protected function extractEntries(?array $structured, string $text): array
    {
        $data = $structured;

        if ($data === null && preg_match('/^\s*[\[{]/', $text)) {
            $data = json_decode($text, true);
        }

        if (! is_array($data)) {
            return [];
        }

        foreach (['results', 'entries', 'pages', 'items', 'data'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data = $data[$key];

                break;
            }
        }

        return array_values(array_filter(
            is_array($data) && array_is_list($data) ? $data : [],
            fn ($entry) => is_array($entry) && filled($entry['id'] ?? $entry['page_id'] ?? null),
        ));
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
