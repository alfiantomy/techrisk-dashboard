<?php

namespace Tests\Unit\Services\Notion;

use App\Services\Notion\NotionIssueMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
     * @dataProvider propertyShapes
     */
    public function test_extracts_plain_values_from_notion_property_objects(?string $type, array $prop, mixed $expected): void
    {
        $this->assertSame($expected, NotionIssueMapper::propertyValue(['Field' => $prop], 'Field'));
    }

    public static function propertyShapes(): array
    {
        return [
            'title' => ['title', ['type' => 'title', 'title' => [['plain_text' => 'DB down']]], 'DB down'],
            'title legacy text key' => ['title', ['type' => 'title', 'title' => [['text' => ['content' => 'Old shape']]]], 'Old shape'],
            'rich_text' => ['rich_text', ['type' => 'rich_text', 'rich_text' => [['plain_text' => 'notes']]], 'notes'],
            'date' => ['date', ['type' => 'date', 'date' => ['start' => 'January 12, 2026']], 'January 12, 2026'],
            'select' => ['select', ['type' => 'select', 'select' => ['name' => 'Code Bug']], 'Code Bug'],
            'status' => ['status', ['type' => 'status', 'status' => ['name' => 'Open']], 'Open'],
            'number' => ['number', ['type' => 'number', 'number' => 3], 3],
            'missing property' => [null, [], null],
            'unknown type' => [null, ['type' => 'people', 'people' => []], null],
        ];
    }
}
