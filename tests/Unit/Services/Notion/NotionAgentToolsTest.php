<?php

namespace Tests\Unit\Services\Notion;

use App\Contracts\AgentToolInterface;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\Tools\NotionCreatePagesTool;
use App\Services\Notion\Tools\NotionFetchTool;
use App\Services\Notion\Tools\NotionSearchTool;
use App\Services\Notion\Tools\NotionUpdatePageTool;
use Tests\TestCase;

class NotionAgentToolsTest extends TestCase
{
    /**
     * @dataProvider toolMappings
     */
    public function test_tools_pass_through_to_their_mcp_tool(string $toolClass, string $mcpTool): void
    {
        $arguments = ['query' => 'payment incidents'];

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('callTool')
            ->once()
            ->with($mcpTool, $arguments)
            ->andReturn('search results');

        $result = app($toolClass)->execute($arguments);

        $this->assertSame('search results', $result);
    }

    public static function toolMappings(): array
    {
        return [
            'search' => [NotionSearchTool::class, 'notion-search'],
            'fetch' => [NotionFetchTool::class, 'notion-fetch'],
            'create pages' => [NotionCreatePagesTool::class, 'notion-create-pages'],
            'update page' => [NotionUpdatePageTool::class, 'notion-update-page'],
        ];
    }

    public function test_tools_satisfy_the_agent_tool_contract(): void
    {
        foreach ([NotionSearchTool::class, NotionFetchTool::class, NotionCreatePagesTool::class, NotionUpdatePageTool::class] as $toolClass) {
            $this->assertInstanceOf(AgentToolInterface::class, app($toolClass));
        }
    }
}
