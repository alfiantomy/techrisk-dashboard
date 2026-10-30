<?php

declare(strict_types=1);

return [
    // Notion's hosted MCP server (streamable HTTP). Auth is OAuth 2.1 with
    // PKCE via laravel/mcp's account-linking routes — no API token involved.
    'mcp_url' => env('NOTION_MCP_URL', 'https://mcp.notion.com/mcp'),

    // Static OAuth client from RFC 7591 dynamic registration (POST to
    // https://mcp.notion.com/register, once). Required on prod: the app
    // domain is VPN-only, so Notion can never fetch the client-metadata URL
    // the package otherwise sends as its client_id ("Invalid client_id").
    // Empty locally → automatic per-connect registration flow still applies.
    'oauth' => [
        'client_id' => env('NOTION_OAUTH_CLIENT_ID'),
        'client_secret' => env('NOTION_OAUTH_CLIENT_SECRET'),
    ],

    // Per-request timeout (seconds) for MCP tool calls.
    'timeout' => (float) env('NOTION_MCP_TIMEOUT', 60),
];
