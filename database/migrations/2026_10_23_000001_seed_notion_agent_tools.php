<?php

declare(strict_types=1);

use App\Models\AgentTool;
use App\Services\Ai\ToolRegistryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;

/**
 * Seeds the four Notion AI-chat tools (backed by Notion's hosted MCP server
 * via the AgentToolInterface classes in App\Services\Notion\Tools).
 *
 * Parameters schemas mirror Notion MCP's documented inputs; they are LLM-facing
 * hints only — the MCP server validates the actual call arguments.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'notion_search',
                'display_name' => 'Notion Search',
                'description' => 'Search the connected Notion workspace (pages, databases, and connected sources) for the given text. Returns matching pages with their IDs.',
                'category' => 'notion',
                'executor_type' => 'class',
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Text to search Notion for.',
                        ],
                    ],
                    'required' => ['query'],
                ],
                'custom_class' => \App\Services\Notion\Tools\NotionSearchTool::class,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'notion_fetch',
                'display_name' => 'Notion Fetch',
                'description' => 'Read a Notion page, database, or data source — pass a Notion URL or ID (search first with notion_search to find IDs). Returns the page content or database entries with properties.',
                'category' => 'notion',
                'executor_type' => 'class',
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'Notion page/database URL or ID to fetch.',
                        ],
                    ],
                    'required' => ['id'],
                ],
                'custom_class' => \App\Services\Notion\Tools\NotionFetchTool::class,
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'name' => 'notion_create_pages',
                'display_name' => 'Notion Create Pages',
                'description' => 'Create one or more pages under a Notion parent page or database. Pass parent.page_id (or parent.data_source_id) and pages[] entries; content is markdown.',
                'category' => 'notion',
                'executor_type' => 'class',
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'parent' => [
                            'type' => 'object',
                            'description' => 'Parent location, e.g. {"page_id": "..."} or {"data_source_id": "..."}.',
                        ],
                        'pages' => [
                            'type' => 'array',
                            'description' => 'Pages to create, each {"properties": {...}, "content": "markdown (optional)"}.',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                    'required' => ['parent', 'pages'],
                ],
                'custom_class' => \App\Services\Notion\Tools\NotionCreatePagesTool::class,
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'notion_update_page',
                'display_name' => 'Notion Update Page',
                'description' => 'Update an existing Notion page: change its properties and/or replace/append content (markdown).',
                'category' => 'notion',
                'executor_type' => 'class',
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'description' => 'Update payload, e.g. {"page_id": "...", "command": "update_content", "properties": {...}, "content": "markdown"}.',
                        ],
                    ],
                    'required' => ['data'],
                ],
                'custom_class' => \App\Services\Notion\Tools\NotionUpdatePageTool::class,
                'is_active' => true,
                'sort_order' => 13,
            ],
        ];

        foreach ($tools as $tool) {
            AgentTool::updateOrCreate(
                ['name' => $tool['name']],
                $tool,
            );
        }

        App::make(ToolRegistryService::class)->clearCache();
    }

    public function down(): void
    {
        AgentTool::whereIn('name', ['notion_search', 'notion_fetch', 'notion_create_pages', 'notion_update_page'])->delete();

        App::make(ToolRegistryService::class)->clearCache();
    }
};
