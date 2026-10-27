<?php

namespace Tests\Feature\Notion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The OAuth routes store the workspace-wide Notion connection — they must be
 * gated exactly like the Notion Settings page (`manage api tokens`), or any
 * logged-in user could repoint the connection at their own Notion account.
 */
class NotionOauthRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('mcp.oauth.notion.connect'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_user_without_permission_gets_403_on_connect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mcp.oauth.notion.connect'))
            ->assertForbidden();
    }

    public function test_user_without_permission_gets_403_on_callback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('mcp.oauth.notion.callback'))
            ->assertForbidden();
    }

    public function test_admin_passes_the_gate(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage api tokens');

        // The gate is the subject under test — after it passes, the connect
        // route performs OAuth discovery against Notion's server, which is
        // not reachable in tests. Fake every HTTP call so the flow fails
        // fast with a non-403 status.
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->actingAs($admin)->get(route('mcp.oauth.notion.connect'));

        $this->assertNotSame(403, $response->status());
    }
}
