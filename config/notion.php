<?php

declare(strict_types=1);

return [
    // Notion's hosted MCP server (streamable HTTP). Auth is OAuth 2.1 with
    // PKCE via laravel/mcp's account-linking routes — no API token involved.
    'mcp_url' => env('NOTION_MCP_URL', 'https://mcp.notion.com/mcp'),

    // Per-request timeout (seconds) for MCP tool calls.
    'timeout' => (float) env('NOTION_MCP_TIMEOUT', 60),
];
