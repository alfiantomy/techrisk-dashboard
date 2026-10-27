<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ActionImprovementResource\Pages\EditActionImprovement;
use App\Filament\Resources\ActionImprovementResource\RelationManagers\EvidenceRelationManager;
use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\ActionImprovementEvidenceKey;
use App\Models\Incident;
use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Encrypted evidence on action improvements (owner 2026-10-26): files upload
 * through the Evidence relation manager encrypted-at-rest (ciphertext under
 * action-evidence/, per-file key row), audit file events on the parent
 * incident, and download through DownloadActionEvidenceController gated by
 * ActionImprovementPolicy::view.
 */
class ActionImprovementEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manage incidents', 'view incidents'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    // ---------------------------------------------------------------
    // Upload through the relation manager
    // ---------------------------------------------------------------

    public function test_upload_encrypts_file_writes_key_row_and_audits_on_incident(): void
    {
        Storage::fake('public');
        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $evidence = $this->uploadEvidence($action, $manager, 'proof.txt', 'plain evidence body');

        $this->assertSame('proof.txt', $evidence->original_filename);
        $this->assertStringStartsWith('action-evidence/', $evidence->file_path);
        $this->assertStringEndsWith('.encrypted', $evidence->file_path);

        $ciphertext = Storage::disk('public')->get($evidence->file_path);
        $this->assertNotNull($ciphertext, 'encrypted file not written to disk');
        $this->assertNotSame('plain evidence body', $ciphertext);
        $this->assertStringNotContainsString('plain evidence body', (string) $ciphertext);

        $key = ActionImprovementEvidenceKey::query()
            ->where('action_improvement_evidence_id', $evidence->id)
            ->first();
        $this->assertNotNull($key, 'per-evidence encryption key row missing');
        $this->assertContains($key->method, ['method1', 'method2', 'method3']);
        $this->assertNotSame('', (string) $key->salt);

        $audit = Audit::query()
            ->where('event', 'file_uploaded')
            ->where('auditable_type', Incident::class)
            ->where('auditable_id', $incident->id)
            ->first();
        $this->assertNotNull($audit, 'incident file_uploaded audit missing');
        $this->assertSame('proof.txt', $audit->new_values['filename']);
    }

    public function test_uploaded_ciphertext_round_trips_through_real_encryption_service(): void
    {
        Storage::fake('public');
        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $evidence = $this->uploadEvidence($action, $manager, 'roundtrip.txt', 'round trip body');

        $encryptionService = app(EncryptionService::class);
        $key = $evidence->evidenceKey;
        $finalKey = $encryptionService->getFinalKey($key->key, $key->salt, $key->method);

        $decrypted = $encryptionService->decrypt(
            (string) Storage::disk('public')->get($evidence->file_path),
            $finalKey,
        );

        $this->assertSame('round trip body', $decrypted);
    }

    // ---------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------

    public function test_delete_removes_encrypted_file_and_key_row(): void
    {
        Storage::fake('public');
        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $evidence = $this->uploadEvidence($action, $manager, 'proof.txt', 'plain evidence body');
        $filePath = $evidence->file_path;

        Livewire::actingAs($manager)
            ->test(EvidenceRelationManager::class, [
                'ownerRecord' => $action,
                'pageClass' => EditActionImprovement::class,
            ])
            ->callTableAction('delete', $evidence)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('action_improvement_evidence', ['id' => $evidence->id]);
        $this->assertDatabaseMissing('action_improvement_evidence_keys', ['action_improvement_evidence_id' => $evidence->id]);
        $this->assertFalse(Storage::disk('public')->exists($filePath), 'encrypted file survived the delete');

        $this->assertNotNull(
            Audit::query()
                ->where('event', 'file_deleted')
                ->where('auditable_type', Incident::class)
                ->where('auditable_id', $incident->id)
                ->first(),
            'incident file_deleted audit missing'
        );
    }

    // ---------------------------------------------------------------
    // Evidence row action in the incident's Action Improvements table
    // ---------------------------------------------------------------

    public function test_evidence_row_action_links_to_edit_page_for_manager_only(): void
    {
        $incident = Incident::factory()->create(['incident_status' => 'In progress']);
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $url = EditActionImprovement::getUrl(['record' => $action]);

        Livewire::actingAs(User::factory()->create()->givePermissionTo('manage incidents'))
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->assertSeeHtml($url);

        Livewire::actingAs(User::factory()->create()->givePermissionTo('view incidents'))
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->assertDontSeeHtml($url);
    }

    // ---------------------------------------------------------------
    // GET /action-improvement-evidence/{record}/download
    // ---------------------------------------------------------------

    public function test_download_returns_decrypted_body_for_user_with_view_permission(): void
    {
        Storage::fake('public');
        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $manager = User::factory()->create()->givePermissionTo('manage incidents');

        $evidence = $this->uploadEvidence($action, $manager, 'fix-proof.txt', 'decrypted evidence body');
        $viewer = User::factory()->create()->givePermissionTo('view incidents');

        $response = $this->actingAs($viewer)->get("/action-improvement-evidence/{$evidence->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('fix-proof.txt', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('decrypted evidence body', $response->streamedContent());

        $this->assertNotNull(
            Audit::query()
                ->where('event', 'file_downloaded')
                ->where('auditable_type', Incident::class)
                ->where('auditable_id', $incident->id)
                ->first(),
            'incident file_downloaded audit missing'
        );
    }

    public function test_download_returns_403_without_permission(): void
    {
        Storage::fake('public');
        $evidence = $this->createEncryptedEvidence('proof.jpg');

        $response = $this->actingAs(User::factory()->create())->get("/action-improvement-evidence/{$evidence->id}/download");

        $response->assertStatus(403);
    }

    public function test_download_returns_404_when_file_missing_from_disk(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create()->givePermissionTo('view incidents');
        $action = ActionImprovement::factory()->create(['incident_id' => Incident::factory()->create()->id]);
        $evidence = ActionImprovementEvidence::create([
            'action_improvement_id' => $action->id,
            'file_path' => 'action-evidence/nonexistent.jpg',
            'original_filename' => 'nonexistent.jpg',
        ]);

        $response = $this->actingAs($viewer)->get("/action-improvement-evidence/{$evidence->id}/download");

        $response->assertStatus(404);
    }

    public function test_download_returns_500_when_encryption_key_missing(): void
    {
        Storage::fake('public');
        $viewer = User::factory()->create()->givePermissionTo('view incidents');
        $action = ActionImprovement::factory()->create(['incident_id' => Incident::factory()->create()->id]);
        $evidence = ActionImprovementEvidence::create([
            'action_improvement_id' => $action->id,
            'file_path' => 'action-evidence/proof.jpg',
            'original_filename' => 'proof.jpg',
        ]);
        Storage::disk('public')->put('action-evidence/proof.jpg', 'dummy content');

        $response = $this->actingAs($viewer)->get("/action-improvement-evidence/{$evidence->id}/download");

        $response->assertStatus(500);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Upload through the real relation manager CreateAction (encrypt-on-upload path). */
    private function uploadEvidence(ActionImprovement $action, User $manager, string $filename, string $content): ActionImprovementEvidence
    {
        $file = UploadedFile::fake()->createWithContent($filename, $content);

        Livewire::actingAs($manager)
            ->test(EvidenceRelationManager::class, [
                'ownerRecord' => $action,
                'pageClass' => EditActionImprovement::class,
            ])
            ->callTableAction('create', data: ['file_path' => $file])
            ->assertHasNoTableActionErrors();

        return ActionImprovementEvidence::query()
            ->where('action_improvement_id', $action->id)
            ->firstOrFail();
    }

    /** Mirrors WebRouteTest::createEncryptedEvidence() — ciphertext + key row seeded directly. */
    private function createEncryptedEvidence(string $filename): ActionImprovementEvidence
    {
        $encryptionService = app(EncryptionService::class);
        $filePath = "action-evidence/{$filename}";

        $incident = Incident::factory()->create();
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $evidence = ActionImprovementEvidence::create([
            'action_improvement_id' => $action->id,
            'file_path' => $filePath,
            'original_filename' => $filename,
        ]);

        $key = $encryptionService->generateKey();
        $salt = $encryptionService->generateSalt();
        $finalKey = $encryptionService->getFinalKey($key, $salt, 'method1');

        ActionImprovementEvidenceKey::create([
            'action_improvement_evidence_id' => $evidence->id,
            'key' => $key,
            'salt' => $salt,
            'method' => 'method1',
        ]);

        Storage::disk('public')->put($filePath, $encryptionService->encrypt("Content of {$filename}", $finalKey));

        return $evidence;
    }
}
