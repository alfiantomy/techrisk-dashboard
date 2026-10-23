<?php

declare(strict_types=1);

namespace App\Services\Notion\Tools;

use App\Contracts\AgentToolInterface;
use App\Services\Notion\NotionMcpClient;

/**
 * Create one or more pages under a parent page/database — AI-chat tool
 * backed by Notion's hosted MCP server.
 */
class NotionCreatePagesTool implements AgentToolInterface
{
    public function __construct(private NotionMcpClient $notion) {}

    public function execute(array $arguments): string
    {
        return $this->notion->callTool('notion-create-pages', $arguments);
    }
}
