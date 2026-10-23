<?php

declare(strict_types=1);

namespace App\Services\Notion\Tools;

use App\Contracts\AgentToolInterface;
use App\Services\Notion\NotionMcpClient;

/**
 * Update an existing Notion page (properties and/or content) — AI-chat tool
 * backed by Notion's hosted MCP server.
 */
class NotionUpdatePageTool implements AgentToolInterface
{
    public function __construct(private NotionMcpClient $notion) {}

    public function execute(array $arguments): string
    {
        return $this->notion->callTool('notion-update-page', $arguments);
    }
}
