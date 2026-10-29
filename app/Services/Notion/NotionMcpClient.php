<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Models\Setting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Client\OAuth\TokenSet;
use Laravel\Mcp\Facades\Mcp;
use Throwable;

/**
 * Workspace-wide Notion MCP connection: encrypted token storage, refresh
 * handling, and tool-call passthrough to Notion's hosted MCP server
 * (https://mcp.notion.com/mcp). Consumed by the AI-chat Notion tools
 * (AgentToolInterface classes) and the scheduled sub-page sync.
 */
class NotionMcpClient
{
    public const TOKEN_KEY = 'notion_mcp_token';

    public const NEEDS_RECONNECT_KEY = 'notion_mcp_needs_reconnect';

    public function connected(): bool
    {
        return $this->payload() !== null;
    }

    /** A refresh failed (e.g. the refresh token was revoked) — an admin must re-run OAuth. */
    public function needsReconnect(): bool
    {
        return (bool) Setting::get(self::NEEDS_RECONNECT_KEY, false);
    }

    public function disconnect(): void
    {
        Setting::where('key', self::TOKEN_KEY)->delete();
        Setting::where('key', self::NEEDS_RECONNECT_KEY)->delete();
        Cache::forget('setting.'.self::TOKEN_KEY);
        Cache::forget('setting.'.self::NEEDS_RECONNECT_KEY);
    }

    public function storeTokens(TokenSet $tokens): void
    {
        $this->storePayload([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'expires_at' => $tokens->expiresAt,
            'client_id' => $tokens->clientId,
            'client_secret' => $tokens->clientSecret,
        ]);
    }

    public function tokenExpiresAt(): ?int
    {
        return $this->payload()['expires_at'] ?? null;
    }

    /**
     * Current valid access token; refreshes when expired. Resolved lazily by
     * the named MCP client's withToken() closure on every request/job.
     */
    public function accessToken(): ?string
    {
        $payload = $this->payload();

        if ($payload === null || blank($payload['access_token'] ?? null) || ! $this->isExpired($payload)) {
            return $payload['access_token'] ?? null;
        }

        return $this->refresh($payload)['access_token'] ?? null;
    }

    /**
     * Call a Notion MCP tool and return its text. Never throws — every
     * failure mode degrades to an error string the chat can read out.
     */
    public function callTool(string $name, array $arguments = []): string
    {
        if (! $this->connected()) {
            return 'Notion is not connected. Ask an admin to connect it on the Notion Settings page.';
        }

        try {
            $result = Mcp::client('notion')->callTool($name, $arguments);
            $text = $result->text();
        } catch (Throwable $e) {
            Log::warning('Notion MCP tool call failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return "Notion tool call failed: {$e->getMessage()}";
        }

        return $result->isError ? "Notion returned an error: {$text}" : $text;
    }

    /**
     * Call a Notion MCP tool and return its full result (structured content
     * + text) or null on any failure. Used by the sync service, which needs
     * machine-readable results rather than display text.
     *
     * @return array{structured: ?array, text: string, isError: bool}|null
     */
    public function callToolDetailed(string $name, array $arguments = []): ?array
    {
        if (! $this->connected()) {
            return null;
        }

        try {
            $result = Mcp::client('notion')->callTool($name, $arguments);
        } catch (Throwable $e) {
            Log::warning('Notion MCP tool call failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return null;
        }

        return ['structured' => $result->structuredContent, 'text' => $result->text(), 'isError' => $result->isError];
    }

    protected function isExpired(array $payload): bool
    {
        $expiresAt = $payload['expires_at'] ?? null;

        return is_numeric($expiresAt) && $expiresAt <= time() + 60;
    }

    protected function refresh(array $payload): array
    {
        if (blank($payload['refresh_token'] ?? null)) {
            return $payload;
        }

        $lock = Cache::lock('notion-mcp-token-refresh', 10);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $payload; // another process is refreshing; use what we hold
        }

        try {
            // Re-read after acquiring: a concurrent process may have refreshed
            // already (Notion rotates refresh tokens on use).
            $payload = $this->payload() ?? $payload;

            if (! $this->isExpired($payload)) {
                return $payload;
            }

            $tokens = Mcp::client('notion')->oAuthClient()->refreshCredentials(
                $payload['refresh_token'],
                $payload['client_id'] ?? null,
                $payload['client_secret'] ?? null,
            );
        } catch (Throwable $e) {
            Log::warning('Notion MCP token refresh failed', ['error' => $e->getMessage()]);
            // Honest state: the connection is no longer self-renewing. A
            // later successful refresh/storePayload clears the flag, so a
            // transient outage never turns into a false alarm.
            Setting::set(self::NEEDS_RECONNECT_KEY, true);

            return $payload; // stale token — the next call surfaces the 401
        } finally {
            $lock->release();
        }

        // Some refresh responses omit the refresh token — keep the old one.
        $merged = array_filter([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $payload['refresh_token'],
            'expires_at' => $tokens->expiresAt,
            'client_id' => $tokens->clientId ?? ($payload['client_id'] ?? null),
            'client_secret' => $tokens->clientSecret ?? ($payload['client_secret'] ?? null),
        ], fn ($v) => $v !== null);

        $this->storePayload($merged);

        return $merged;
    }

    protected function storePayload(array $payload): void
    {
        Setting::set(self::TOKEN_KEY, Crypt::encryptString(json_encode(array_filter(
            $payload,
            fn ($v) => $v !== null
        ))));
        // Fresh tokens landed (OAuth or a successful refresh) — connection is
        // self-renewing again.
        Setting::where('key', self::NEEDS_RECONNECT_KEY)->delete();
        Cache::forget('setting.'.self::NEEDS_RECONNECT_KEY);
    }

    /**
     * @return array<string, mixed>|null decrypted token payload
     */
    protected function payload(): ?array
    {
        $encrypted = Setting::get(self::TOKEN_KEY);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
        } catch (Throwable) {
            Log::warning('Notion MCP token payload unreadable — treating as disconnected');

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
