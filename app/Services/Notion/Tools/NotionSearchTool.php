<?php

declare(strict_types=1);

namespace App\Services\Notion\Tools;

use App\Contracts\AgentToolInterface;
use App\Services\Notion\NotionMcpClient;

/**
 * Search Notion (pages, databases, connected sources) — AI-chat tool backed
 * by Notion's hosted MCP server. Arguments pass through verbatim; the MCP
 * server validates them and errors surface as readable text.
 */
class NotionSearchTool implements AgentToolInterface
{
    public function __construct(private NotionMcpClient $notion) {}

    public function execute(array $arguments): string
    {
        return $this->notion->callTool('notion-search', $arguments);
    }
}
