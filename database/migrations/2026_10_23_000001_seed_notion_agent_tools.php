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
                            'description' => 'Short, specific keywords for Notion content search.',
                        ],
                        'query_type' => [
                            'type' => 'string',
                            'enum' => ['internal', 'user'],
                            'description' => 'Search pages ("internal") or people ("user").',
                        ],
                        'page_size' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results (1-50, default 10).',
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
                        'pages' => [
                            'type' => 'array',
                            'description' => 'Pages to create, each {"properties": {...}, "content": "markdown (optional)"}. For database pages, properties keys/follow the database\'s SQLite schema (dates as "date:{Property}:start", numbers as JSON numbers, checkboxes "__YES__"/"__NO__"); for non-database pages the only property is "title".',
                            'items' => ['type' => 'object'],
                        ],
                        'parent' => [
                            'type' => 'object',
                            'description' => 'Parent location, e.g. {"page_id": "..."} or {"data_source_id": "..."}. Omit to create a private draft page.',
                        ],
                        'creation_mode' => [
                            'type' => 'string',
                            'enum' => ['draft'],
                            'description' => 'Set to "draft" (with no parent) to create a private draft page.',
                        ],
                    ],
                    'required' => ['pages'],
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
                        'page_id' => [
                            'type' => 'string',
                            'description' => 'Notion page ID or URL to update.',
                        ],
                        'command' => [
                            'type' => 'string',
                            'enum' => ['update_properties', 'update_content', 'replace_content', 'insert_content'],
                            'description' => 'update_properties: change property values; update_content: search-and-replace via content_updates; replace_content: rewrite the whole page; insert_content: append markdown.',
                        ],
                        'properties' => [
                            'type' => 'object',
                            'description' => 'For update_properties: {"Property": value} in the database\'s SQLite format (dates as "date:{Property}:start", checkboxes "__YES__"/"__NO__", numbers as JSON numbers).',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Markdown for insert_content.',
                        ],
                        'content_updates' => [
                            'type' => 'array',
                            'description' => 'For update_content: [{"old_str": "exact existing text", "new_str": "replacement"}].',
                        ],
                        'new_str' => [
                            'type' => 'string',
                            'description' => 'For replace_content: the full new page content.',
                        ],
                    ],
                    'required' => ['page_id', 'command'],
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
