<?php

namespace Tests\Unit\Services\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Services\Notion\NotionIssueMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Title dedupe must see the WHOLE table, not just Issues — the pre-sync
 * backlog was partly entered as Incidents (owner 2026-10-29), and the CSV
 * importer rides the same check as the Notion sync.
 */
class NotionIssueMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleans_notion_title_prefix(): void
    {
        $this->assertSame('DB down', NotionIssueMapper::cleanTitle('Summary of Incident - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::cleanTitle('DB down'));
    }

    /**
     * @dataProvider severityMappings
     */
    public function test_maps_notion_classifications_to_severity(string $classification, string $expected): void
    {
        $this->assertSame($expected, NotionIssueMapper::mapSeverityToCode($classification));
    }

    public static function severityMappings(): array
    {
        return [
            'deployment' => ['Deployment Issues', 'P1'],
            'infrastructure' => ['Infrastructure Issue', 'P1'],
            'code bug' => ['Code Bug', 'P2'],
            'configuration' => ['Configuration Error', 'P2'],
            'data' => ['Data Issue', 'P3'],
            'ui' => ['UI Issue', 'P4'],
            'internal' => ['Internal Improving Issue', 'G'],
            'unknown falls back to G' => ['Something New', 'G'],
        ];
    }

    public function test_parses_notion_date_format_and_fallback(): void
    {
        $this->assertSame('2026-01-12', NotionIssueMapper::parseNotionDate('January 12, 2026')->toDateString());
        $this->assertSame('2026-01-12', NotionIssueMapper::parseNotionDate('2026-01-12')->toDateString());
    }

    public function test_generate_issue_id_has_expected_shape_and_is_unique(): void
    {
        $first = NotionIssueMapper::generateIssueId();
        $second = NotionIssueMapper::generateIssueId();

        $this->assertMatchesRegularExpression('/^\d{8}_IS_\d{4}$/', $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * @dataProvider classificationSeverities
     */
    public function test_maps_classification_column_to_severity(?string $classification, ?string $expected): void
    {
        $this->assertSame($expected, NotionIssueMapper::mapClassificationToSeverity($classification));
    }

    public static function classificationSeverities(): array
    {
        return [
            'P1' => ['P1', 'P1'],
            'X4' => ['X4', 'X4'],
            'padded' => [' P2 ', 'P2'],
            'unclassified dash' => ['-', null],
            'null' => [null, null],
            'not a severity' => ['Deployment Issues', null],
            'trailing digit out of range' => ['P5', null],
        ];
    }

    private function incident(string $classification): Incident
    {
        return Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => $classification,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);
    }

    public function test_find_duplicate_by_title_matches_an_incident_classified_row(): void
    {
        $incident = $this->incident(IncidentClassification::Incident->value);

        $this->assertSame($incident->id, NotionIssueMapper::findDuplicateByTitle('Summary of Incident - DB DOWN')?->id);
    }

    public function test_existing_normalized_titles_includes_incident_rows(): void
    {
        $this->incident(IncidentClassification::Incident->value);

        $this->assertArrayHasKey('db down', NotionIssueMapper::existingNormalizedTitles());
    }
}
