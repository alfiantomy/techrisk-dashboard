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
     * Pull a plain value out of a Notion API/MCP property object
     * ({"Title": {"type": "title", "title": [{"plain_text": ...}]}, ...}).
     */
    public static function propertyValue(array $properties, string $name): mixed
    {
        $prop = $properties[$name] ?? null;

        if (! is_array($prop)) {
            return $prop ?? null;
        }

        return match ($prop['type'] ?? null) {
            'title' => $prop['title'][0]['plain_text'] ?? ($prop['title'][0]['text']['content'] ?? null),
            'rich_text' => $prop['rich_text'][0]['plain_text'] ?? null,
            'date' => $prop['date']['start'] ?? null,
            'select', 'status' => $prop[$prop['type']]['name'] ?? null,
            'number' => $prop['number'],
            'url', 'email', 'phone_number' => $prop[$prop['type']] ?? null,
            default => null,
        };
    }
}
