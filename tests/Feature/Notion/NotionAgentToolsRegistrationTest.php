<?php

namespace Tests\Feature\Notion;

use App\Models\AgentTool;
use App\Services\Ai\ToolRegistryService;
use App\Services\Ai\Tools\ExternalToolExecutor;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionAgentToolsRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_four_notion_tools(): void
    {
        foreach (['notion_search', 'notion_fetch', 'notion_create_pages', 'notion_update_page'] as $name) {
            $tool = AgentTool::where('name', $name)->first();

            $this->assertNotNull($tool, "AgentTool {$name} missing");
            $this->assertSame('class', $tool->executor_type);
            $this->assertSame('notion', $tool->category);
            $this->assertTrue($tool->is_active);
            $this->assertArrayHasKey('type', $tool->parameters_schema);
        }
    }

    public function test_registry_exposes_notion_tools_to_chat(): void
    {
        $definitions = collect(app(ToolRegistryService::class)->getToolDefinitions());

        // AgenticChatService calls getToolDefinitions() unfiltered — every
        // seeded tool reaches the AI chat automatically.
        foreach (['notion_search', 'notion_fetch', 'notion_create_pages', 'notion_update_page'] as $name) {
            $this->assertTrue($definitions->contains(fn ($def) => $def['function']['name'] === $name), "{$name} not in registry");
        }
    }

    public function test_seeded_tool_executes_end_to_end_via_the_registry(): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callTool')->once()->with('notion-search', ['query' => 'payment'])
            ->andReturn('found 3 pages');

        $tool = AgentTool::where('name', 'notion_search')->firstOrFail();

        $result = app(ExternalToolExecutor::class)->execute($tool, ['query' => 'payment']);

        $this->assertSame('found 3 pages', $result);
    }
}
