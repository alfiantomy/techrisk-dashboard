<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Enums\IncidentClassification;
use App\Helpers\StringHelper;
use App\Models\Incident;
use Illuminate\Support\Carbon;

/**
 * Single source for Notion → Issue field mapping, shared by the manual CSV
 * importer (App\Filament\Importers\IssuesImporter) and the scheduled
 * sub-page sync (NotionIssueSyncService). Extracted from the importer so
 * the two paths cannot drift (see BUG-008).
 */
class NotionIssueMapper
{
    /** Clean a Notion page name into a dashboard title. */
    public static function cleanTitle(string $name): string
    {
        return str_replace('Summary of Incident - ', '', $name);
    }

    /** Parse "Month DD, YYYY" (Notion's format) with a generic fallback. */
    public static function parseNotionDate(string $dateString): Carbon
    {
        try {
            return Carbon::createFromFormat('F d, Y', $dateString)->startOfDay();
        } catch (\Exception) {
            return Carbon::parse($dateString);
        }
    }

    /** Map Notion root-cause classifications to severity codes. */
    public static function mapSeverityToCode(string $classification): string
    {
        $mapping = [
            'Deployment Issues' => 'P1',
            'Infrastructure Issue' => 'P1',
            'Code Bug' => 'P2',
            'Configuration Error' => 'P2',
            'Data Issue' => 'P3',
            'UI Issue' => 'P4',
            'Internal Improving Issue' => 'G',
        ];

        return $mapping[$classification] ?? 'G';
    }

    /**
     * Severity straight from the database's own "Classification by Tech Risk
     * Incident" column (P1–P4 / X1–X4); null when unclassified ("-"/blank/other)
     * so the caller can fall back to mapSeverityToCode().
     */
    public static function mapClassificationToSeverity(?string $classification): ?string
    {
        return $classification !== null && preg_match('/^[PX][1-4]$/', trim($classification)) ? trim($classification) : null;
    }

    /** Generate a unique issue record number (Ymd_IS_xxxx). */
    public static function generateIssueId(): string
    {
        $baseId = date('Ymd').'_IS_';

        do {
            $uniqueId = $baseId.random_int(1000, 9999);
        } while (Incident::where('no', $uniqueId)->exists());

        return $uniqueId;
    }

    /** Case-insensitive/normalized duplicate check against existing issues. */
    public static function findDuplicateByTitle(string $name): ?Incident
    {
        $normalizedTitle = StringHelper::normalizeForComparison($name);

        return Incident::where('classification', IncidentClassification::Issue->value)
            ->get()
            ->first(fn (Incident $incident) => StringHelper::normalizeForComparison((string) $incident->title) === $normalizedTitle);
    }

    /**
     * Normalized titles of every existing Issue, as a set — one query. The
     * sync service uses this instead of the per-row findDuplicateByTitle()
     * hydration above (which loads the whole Issue table for every row).
     */
    public static function existingNormalizedTitles(): array
    {
        return Incident::where('classification', IncidentClassification::Issue->value)
            ->pluck('title')
            ->mapWithKeys(fn ($title) => [StringHelper::normalizeForComparison((string) $title) => true])
            ->all();
    }
}
