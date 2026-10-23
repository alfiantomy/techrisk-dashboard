<?php

declare(strict_types=1);

namespace App\Services\Notion\Tools;

use App\Contracts\AgentToolInterface;
use App\Services\Notion\NotionMcpClient;

/**
 * Fetch a Notion page, database, or data source by ID/URL — AI-chat tool
 * backed by Notion's hosted MCP server.
 */
class NotionFetchTool implements AgentToolInterface
{
    public function __construct(private NotionMcpClient $notion) {}

    public function execute(array $arguments): string
    {
        return $this->notion->callTool('notion-fetch', $arguments);
    }
}
