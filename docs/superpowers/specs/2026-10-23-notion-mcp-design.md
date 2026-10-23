# Notion via MCP — Connection, Chat Tools, Scheduled Sub-page Sync

**Date:** 2026-10-23
**Branch:** `feature/notion-mcp`
**Status:** Implemented (live verification pending — see "Verification")

## Goal

The dashboard connects to Notion via MCP (Model Context Protocol):

1. **MCP connection** — the app acts as an MCP client to Notion's hosted MCP server (`https://mcp.notion.com/mcp`, streamable HTTP), one workspace-wide OAuth connection.
2. **AI chat tools** (read + write) — Notion tools available in the AI chat only. War Room is excluded by existing mechanics (it only gets tools listed in its config's `enabled_tools`, default none); scheduled agents are text-only by design.
3. **Scheduled sub-page sync** — a scheduler watches a configured Notion page/database; new sub-pages (database entries with properties) are auto-imported into the **Issue list** (`classification='Issue'`), following the existing Notion CSV importer's field mapping.

## Existing seams used

- `App\Contracts\AgentToolInterface` (`execute(array $arguments): string`) + `App\Models\AgentTool` DB rows (`executor_type='class'`) + `App\Services\Ai\ToolRegistryService` (300s cache, `agent_tools_external_active`). `AgenticChatService` takes **all** registry tools unfiltered, so new rows reach chat automatically — no exposure code needed.
- `App\Filament\Importers\IssuesImporter.php` — existing Notion-CSV → Issue mapping. Its helpers were extracted into `App\Services\Notion\NotionIssueMapper` so the CSV importer and MCP sync share one implementation (BUG-008 drift lesson: one source, many surfaces).
- `App\Models\Setting` — key/value store (Netcore precedent) for the encrypted token payload and sync state.
- Scheduling lives in `routes/console.php`.

## Library choice

`laravel/mcp` v1.1+ ships the MCP **client** (pre-1.1 was server-only):

- `Client::web(url)`, `withOAuth()` (bare call → dynamic client registration RFC 7591, which Notion supports — no manual Notion app needed), `withToken(string|Closure)` (lazy), `withTimeout()`.
- `Mcp::registerClient('notion', factory)` in `AppServiceProvider::boot()`.
- `Mcp::oAuthRoutesFor('notion', handler)` registers `mcp.oauth.notion.{connect,callback,client-metadata}` — connect at `/mcp/notion/connect`, callback at `/mcp/oauth/notion/callback`. The handler receives the exchanged `TokenSet` and stores it.
- `callTool(name, args)` → `ToolResult` (`text()`, `isError`, `structuredContent`).
- Token refresh is our job: `OAuthClient::refreshCredentials(refreshToken, clientId, clientSecret)`.
- Note: `tools/list` on Notion's server is OAuth-gated, so tool parameter schemas are seeded statically; exact response shapes must be inspected live via `notion:sync-issues --dry-run`.

## Component design

### `app/Services/Notion/NotionMcpClient.php` — connection layer

One service, no interface. Owns token storage + refresh + tool calls.

- `connected(): bool`, `storeTokens(TokenSet)`, `disconnect()`, `tokenExpiresAt()`
- `accessToken(): ?string` — refreshes via stored refresh token when expired (60s safety margin), guarded by `Cache::lock('notion-mcp-token-refresh', 10)` and a **re-read after acquiring the lock** (Notion rotates refresh tokens on use; a stale read would store an already-rotated-away token).
- Token payload: JSON `{access, refresh, expires_at, client_id, client_secret}` encrypted with `Crypt::encryptString` into `Setting` key `notion_mcp_token`. Unreadable ciphertext → log + report disconnected (never crash).
- `callTool(name, args): string` — never throws. Not connected → friendly message ("Notion is not connected. Ask an admin to connect it on the Notion Settings page."); `isError` → "Notion returned an error: …"; transport exception → "Notion tool call failed: …". This is what chat users see.
- `callToolDetailed(name, args): ?array{structured, text, isError}` — one call, full result, for the sync service (no double network round-trip).

Registered in `AppServiceProvider::boot()`; OAuth callback in `routes/web.php` stores tokens and redirects to the Notion Settings page. `APP_URL` must be publicly reachable (Notion redirects the browser back to it).

### `app/Services/Notion/Tools/` — AI chat tools (Phase B)

Four classes implementing `AgentToolInterface`, each a thin passthrough to `callTool()`:

| Class | MCP tool |
|---|---|
| `NotionSearchTool` | `notion-search` |
| `NotionFetchTool` | `notion-fetch` |
| `NotionCreatePagesTool` | `notion-create-pages` |
| `NotionUpdatePageTool` | `notion-update-page` |

`database/migrations/2026_10_23_000001_seed_notion_agent_tools.php` seeds 4 `AgentTool` rows (`executor_type='class'`, `category='notion'`, static OpenAI-style `parameters_schema` because `tools/list` is OAuth-gated) and clears the registry cache. Chat exposure is automatic via the unfiltered registry.

### `NotionIssueMapper` — shared Notion → Issue mapping

Static helpers used by **both** the CSV importer and the MCP sync:

- `cleanTitle()` (strips "Summary of Incident - "), `parseNotionDate()` ("F d, Y" then `Carbon::parse` fallback), `mapSeverityToCode()` (Deployment Issues/Infrastructure Issue → P1, Code Bug/Configuration Error → P2, Data Issue → P3, UI Issue → P4, else G), `generateIssueId()` (`Ymd_IS_xxxx`), `findDuplicateByTitle()` (normalized-title dedupe within `classification = Issue` — from enum case, never a hand-typed string), `propertyValue()` (extracts plain values from Notion property objects).

### `NotionIssueSyncService` — scheduled sub-page sync (Phase C)

- Reads the watch target from `Setting` key `notion_watch_page_id` (set on the Notion Settings page).
- `sync(bool $dryRun)` calls `notion-fetch` on the target via `callToolDetailed()`, extracts entries (`extractEntries()` tries `structuredContent` list keys → JSON-in-text fallback — shapes are finalized against the live server, see Verification), dedupes on Notion page ID via the `notion_synced_pages` table (**page identity, not title** — titles get edited), maps properties through `NotionIssueMapper`, creates `Incident` rows with `classification = Issue`, `incident_type = 'Tech'`, `incident_source = 'Internal'` (the CSV importer leaves the NOT NULL type/source enums unset — sync must not).
- Records `notion_last_sync_at` + `notion_last_sync_status` in `Setting`; dry-run touches nothing.
- `notion:sync-issues {--dry-run}` command; scheduled every 15 min `withoutOverlapping()` in `routes/console.php`.
- New pages only — no rename/delete/write-back handling (YAGNI).

### Filament admin surface (Phase D)

`app/Filament/Pages/NotionSettings.php` (System group, gated on `manage api tokens`): connection status + expiry, Connect button (→ `mcp.oauth.notion.connect`), Disconnect, last-sync status, watch page ID field (accepts a URL — the bare 32-hex ID is extracted).

## Tests

- `tests/Unit/Services/Notion/NotionMcpClientTest.php` — 9 tests: not-connected default, store + encrypt-at-rest (raw row must not contain the token), disconnect, friendly not-connected message, tool passthrough, isError mapping, transport-exception survival, expired-token refresh (lock + rotation-safe re-read), stale token without refresh token, unreadable ciphertext → disconnected.
- `tests/Unit/Services/Notion/NotionAgentToolsTest.php` — the 4 tools map to the right MCP tool names and pass arguments through.
- `tests/Unit/Services/Notion/NotionIssueMapperTest.php` — title cleaning, severity table, date parsing, ID generation, property-shape extraction.
- `tests/Feature/Notion/NotionIssueSyncServiceTest.php` — 8 tests: no watch target / not connected fail fast; first run creates Issues (fields, synced-page row, last-sync status); second run creates nothing (dedupe); dry-run creates nothing; fetch failure leaves DB untouched with error status; unparseable response reports raw preview; unmapped severity → G.
- `tests/Feature/Notion/NotionAgentToolsRegistrationTest.php` — seeding, registry exposure to chat, end-to-end execution via `ExternalToolExecutor` with a mocked client.

## Not built (YAGNI)

- No per-user Notion connections (one workspace-wide OAuth connection).
- No War Room / scheduled-agent tool exposure.
- No live schema sync from `tools/list`; no write-back dashboard → Notion; no sub-page deletion/rename handling; no re-sync of already-imported pages.

## Verification

1. ✅ `php artisan test` — 61 Notion/importer tests green; full suite 907 tests with only 7 failures **verified pre-existing** (6 × Reverb `BroadcastException` + 1 × ChatPersonaParityTest — identical on a clean tree via stash).
2. ⏳ **Live (needs deployed/tunneled APP_URL + Docker up):** connect via the Notion Settings page → approve in Notion → `php artisan notion:sync-issues --dry-run` against the real watch target. **First dry-run reveals the actual `notion-fetch` response shape** — if `extractEntries()` needs adjustment, that's a one-place change in the sync service (possibly switching to `notion-query-data-source`). Then: real run imports new sub-pages into the Issue list; second run imports nothing; chat "search my Notion for X" works.
3. ✅ Pint clean.
4. ✅ War Room config untouched → Notion tools absent from its definitions.
5. ⏳ `php artisan migrate --force` on the real DB at deploy time (Docker was down during development; SQLite in-memory used for tests).
