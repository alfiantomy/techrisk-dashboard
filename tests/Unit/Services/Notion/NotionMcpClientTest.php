<?php

namespace Tests\Unit\Services\Notion;

use App\Models\Setting;
use App\Services\Notion\NotionMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Client\OAuth\OAuthClient;
use Laravel\Mcp\Client\OAuth\TokenSet;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\WebClient;
use Mockery;
use Tests\TestCase;

class NotionMcpClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_starts_disconnected(): void
    {
        $client = app(NotionMcpClient::class);

        $this->assertFalse($client->connected());
        $this->assertNull($client->accessToken());
        $this->assertNull($client->tokenExpiresAt());
    }

    public function test_store_tokens_makes_it_connected_and_encrypted_at_rest(): void
    {
        $client = app(NotionMcpClient::class);

        $client->storeTokens(new TokenSet(
            accessToken: 'secret-access-token',
            refreshToken: 'secret-refresh-token',
            expiresAt: time() + 3600,
            clientId: 'client-1',
            clientSecret: 'client-secret-1',
        ));

        $this->assertTrue($client->connected());
        $this->assertSame('secret-access-token', $client->accessToken());

        // Encrypted at rest — the raw Setting row must not contain the token.
        $raw = Setting::where('key', NotionMcpClient::TOKEN_KEY)->value('value');

        $this->assertStringNotContainsString('secret-access-token', (string) $raw);
    }

    public function test_disconnect_clears_the_connection(): void
    {
        $client = app(NotionMcpClient::class);

        $client->storeTokens(new TokenSet(accessToken: 'a', refreshToken: 'r'));
        $client->disconnect();

        $this->assertFalse($client->connected());
    }

    public function test_call_tool_when_not_connected_returns_friendly_message(): void
    {
        Mcp::shouldReceive('client')->never();

        $result = app(NotionMcpClient::class)->callTool('notion-search', ['query' => 'x']);

        $this->assertStringContainsString('Notion is not connected', $result);
    }

    public function test_call_tool_returns_text_and_passes_arguments(): void
    {
        $this->storeExpiringToken();

        $webClient = Mockery::mock(WebClient::class);
        $webClient->shouldReceive('callTool')->once()->with('notion-fetch', ['id' => 'page-1'])
            ->andReturn(new \Laravel\Mcp\Client\Schema\ToolResult(
                content: [['type' => 'text', 'text' => 'page content']],
                isError: false,
            ));
        Mcp::shouldReceive('client')->once()->with('notion')->andReturn($webClient);

        $result = app(NotionMcpClient::class)->callTool('notion-fetch', ['id' => 'page-1']);

        $this->assertSame('page content', $result);
    }

    public function test_call_tool_maps_server_errors_to_text(): void
    {
        $this->storeExpiringToken();

        $webClient = Mockery::mock(WebClient::class);
        $webClient->shouldReceive('callTool')->once()->andReturn(new \Laravel\Mcp\Client\Schema\ToolResult(
            content: [['type' => 'text', 'text' => 'Could not find page']],
            isError: true,
        ));
        Mcp::shouldReceive('client')->once()->andReturn($webClient);

        $result = app(NotionMcpClient::class)->callTool('notion-fetch');

        $this->assertStringContainsString('Notion returned an error', $result);
        $this->assertStringContainsString('Could not find page', $result);
    }

    public function test_call_tool_survives_transport_exceptions(): void
    {
        $this->storeExpiringToken();

        $webClient = Mockery::mock(WebClient::class);
        $webClient->shouldReceive('callTool')->once()->andThrow(new \RuntimeException('connection reset'));
        Mcp::shouldReceive('client')->once()->andReturn($webClient);

        $result = app(NotionMcpClient::class)->callTool('notion-fetch');

        $this->assertStringContainsString('Notion tool call failed', $result);
        $this->assertStringContainsString('connection reset', $result);
    }

    public function test_expired_access_token_is_refreshed_and_restored(): void
    {
        app(NotionMcpClient::class)->storeTokens(new TokenSet(
            accessToken: 'old-access',
            refreshToken: 'refresh-1',
            expiresAt: time() - 100,
            clientId: 'client-1',
            clientSecret: 'secret-1',
        ));

        $oauthClient = Mockery::mock(OAuthClient::class);
        $oauthClient->shouldReceive('refreshCredentials')
            ->once()
            ->with('refresh-1', 'client-1', 'secret-1')
            ->andReturn(new TokenSet(
                accessToken: 'new-access',
                refreshToken: 'refresh-2',
                expiresAt: time() + 3600,
            ));

        $webClient = Mockery::mock(WebClient::class);
        $webClient->shouldReceive('oAuthClient')->once()->andReturn($oauthClient);
        Mcp::shouldReceive('client')->once()->with('notion')->andReturn($webClient);

        $client = app(NotionMcpClient::class);

        $this->assertSame('new-access', $client->accessToken());
        $this->assertFalse($client->tokenExpiresAt() <= time() + 60);
    }

    public function test_expired_token_without_refresh_token_returns_stale_token(): void
    {
        app(NotionMcpClient::class)->storeTokens(new TokenSet(accessToken: 'stale', expiresAt: time() - 100));

        Mcp::shouldReceive('client')->never();

        $this->assertSame('stale', app(NotionMcpClient::class)->accessToken());
    }

    public function test_unreadable_ciphertext_reads_as_disconnected(): void
    {
        Setting::set(NotionMcpClient::TOKEN_KEY, 'not-encrypted-garbage');

        $client = app(NotionMcpClient::class);

        $this->assertFalse($client->connected());
        $this->assertNull($client->accessToken());
    }

    private function storeExpiringToken(): void
    {
        app(NotionMcpClient::class)->storeTokens(new TokenSet(accessToken: 'tok', refreshToken: 'r', expiresAt: time() + 3600));
    }
}
